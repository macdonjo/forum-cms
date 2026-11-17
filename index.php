<?php
define('ROOT', __DIR__);

if (!file_exists(ROOT . '/config.php')) {
    if (file_exists(ROOT . '/install.php')) {
        header('Location: /install.php');
    } else {
        http_response_code(503);
        echo 'Forum not configured. Upload config.php to continue.';
    }
    exit;
}

$config = require ROOT . '/config.php';

require ROOT . '/src/DB.php';
require ROOT . '/src/Router.php';
require ROOT . '/src/Auth.php';
require ROOT . '/src/helpers.php';
require ROOT . '/src/Updater.php';
require ROOT . '/src/API.php';

Auth::start();

// Auto-migrate: role column
$_cols = array_column(DB::all("SHOW COLUMNS FROM users LIKE 'role'"), 'Field');
if (empty($_cols)) {
    DB::execute("ALTER TABLE users ADD COLUMN role ENUM('user','moderator','admin') NOT NULL DEFAULT 'user'");
    DB::execute("UPDATE users SET role = 'admin' WHERE is_admin = 1");
}
unset($_cols);

// Auto-migrate: source column — 'web' (made on the site) or 'api' (made through the API)
foreach (['users', 'threads', 'replies'] as $_t) {
    if (!DB::all("SHOW COLUMNS FROM $_t LIKE 'source'")) {
        DB::execute("ALTER TABLE $_t ADD COLUMN source ENUM('web','api') NOT NULL DEFAULT 'web'");
    }
}
unset($_t);

// Auto-migrate: settings table + API key
DB::execute("CREATE TABLE IF NOT EXISTS settings (
    `key`  VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
DB::execute("INSERT IGNORE INTO settings (`key`, `value`) VALUES ('api_key', ?)", [API::generateKey()]);

// Once per day, check for updates after the response is sent so users feel nothing
if (Updater::shouldCheck()) {
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        @set_time_limit(120);
        @ignore_user_abort(true);
        Updater::checkAndUpdate();
    });
}

// ── Load + seed editable settings ────────────────────────────────────────────
$_s = array_column(DB::all("SELECT `key`, `value` FROM settings"), 'value', 'key');
foreach ([
    'forum_name'         => $config['app_name'],
    'forum_description'  => 'A forum. Join the conversation.',
    'allow_registration' => '1',
    'posts_per_page'     => '20',
] as $_k => $_v) {
    if (!array_key_exists($_k, $_s)) {
        DB::execute("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)", [$_k, $_v]);
        $_s[$_k] = $_v;
    }
}
if (!empty($_s['forum_name']))    $config['app_name'] = $_s['forum_name'];
$config['forum_description']  = $_s['forum_description']  ?? 'A forum. Join the conversation.';
$config['allow_registration'] = ($_s['allow_registration'] ?? '1') === '1';
$config['posts_per_page']     = max(5, min(100, (int)($_s['posts_per_page'] ?? 20)));
unset($_s, $_k, $_v);

// ── Scheduled content ────────────────────────────────────────────────────────
// Threads/replies may carry a future created_at (via the API). Public queries
// filter on created_at <= NOW(), so they appear on time regardless. The stored
// threads.reply_count / threads.updated_at only reflect *visible* replies, so once
// a minute we catch up the threads whose scheduled replies have since gone live.
publish_due();

$router   = new Router();
$PER_PAGE = $config['posts_per_page'];

// ── Home ──────────────────────────────────────────────────────────────────────
$router->add('GET', '/', function() use ($config) {
    $sections = DB::all("
        SELECT s.*,
               COUNT(DISTINCT t.id) AS thread_count,
               MAX(t.updated_at)    AS last_activity
        FROM sections s
        LEFT JOIN threads t ON t.section_id = s.id AND t.created_at <= NOW()
        GROUP BY s.id
        ORDER BY s.display_order, s.name
    ");
    render('home', [
        'sections'    => $sections,
        'title'       => $config['app_name'],
        'description' => $config['forum_description'],
        'canonical'   => $config['app_url'] . '/',
        'schema'      => json_encode([
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $config['app_name'],
            'url'      => $config['app_url'] . '/',
        ]),
    ]);
});

// ── New Thread ────────────────────────────────────────────────────────────────
$router->add('GET', '/new-thread', function() use ($config) {
    Auth::require();
    $sections  = DB::all("SELECT * FROM sections ORDER BY display_order, name");
    $preselect = $_GET['section'] ?? '';
    render('new_thread', [
        'sections'    => $sections,
        'preselect'   => $preselect,
        'title'       => 'New Thread — ' . $config['app_name'],
        'description' => '',
        'noindex'     => true,
    ]);
});

$router->add('POST', '/new-thread', function() use ($config) {
    Auth::require();
    csrf_verify();

    $title      = trim($_POST['title'] ?? '');
    $body       = trim($_POST['body'] ?? '');
    $section_id = (int)($_POST['section_id'] ?? 0);
    $errors     = [];

    if (!$title)      $errors[] = 'Title is required.';
    if (!$body)       $errors[] = 'Body is required.';
    if (!$section_id) $errors[] = 'Please select a section.';

    $section = $section_id ? DB::one("SELECT * FROM sections WHERE id = ?", [$section_id]) : null;
    if ($section_id && !$section) $errors[] = 'Invalid section.';

    if ($errors) {
        $sections = DB::all("SELECT * FROM sections ORDER BY display_order, name");
        render('new_thread', [
            'sections'    => $sections,
            'preselect'   => '',
            'errors'      => $errors,
            'old'         => $_POST,
            'title'       => 'New Thread — ' . $config['app_name'],
            'description' => '',
        ]);
        return;
    }

    $image = !empty($_FILES['image']['name']) ? upload_image($_FILES['image']) : null;
    $slug  = unique_slug('threads', $title, 'section_id', $section_id);
    $user  = Auth::user();

    DB::execute(
        "INSERT INTO threads (section_id, user_id, title, slug, body, image) VALUES (?, ?, ?, ?, ?, ?)",
        [$section_id, $user['id'], $title, $slug, $body, $image]
    );

    redirect('/' . $section['slug'] . '/' . $slug);
});

// ── Reply ─────────────────────────────────────────────────────────────────────
$router->add('POST', '/reply', function() {
    Auth::require();
    csrf_verify();

    $thread_id = (int)($_POST['thread_id'] ?? 0);
    $body      = trim($_POST['body'] ?? '');

    $thread = $thread_id ? DB::one("
        SELECT t.*, s.slug AS section_slug
        FROM threads t JOIN sections s ON s.id = t.section_id
        WHERE t.id = ? AND t.created_at <= NOW()
    ", [$thread_id]) : null;

    if (!$thread || !$body) {
        flash('error', 'Could not post reply.');
        redirect($_SERVER['HTTP_REFERER'] ?? '/');
        return;
    }

    $image = !empty($_FILES['image']['name']) ? upload_image($_FILES['image']) : null;
    $user  = Auth::user();

    DB::execute(
        "INSERT INTO replies (thread_id, user_id, body, image) VALUES (?, ?, ?, ?)",
        [$thread_id, $user['id'], $body, $image]
    );
    DB::execute(
        "UPDATE threads SET reply_count = reply_count + 1, updated_at = NOW() WHERE id = ?",
        [$thread_id]
    );

    $count     = (int)DB::one("SELECT COUNT(*) AS c FROM replies WHERE thread_id = ? AND created_at <= NOW()", [$thread_id])['c'];
    $last_page = max(1, (int)ceil($count / 30));
    $url       = '/' . $thread['section_slug'] . '/' . $thread['slug']
                 . ($last_page > 1 ? '?page=' . $last_page : '');
    redirect($url . '#bottom');
});

// ── Login ─────────────────────────────────────────────────────────────────────
$router->add('GET', '/login', function() use ($config) {
    if (Auth::check()) redirect('/');
    render('login', ['title' => 'Login — ' . $config['app_name'], 'description' => '', 'noindex' => true]);
});

$router->add('POST', '/login', function() use ($config) {
    csrf_verify();
    $login = trim($_POST['login'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $user  = DB::one("SELECT * FROM users WHERE email = ? OR username = ?", [$login, $login]);

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        render('login', [
            'title'       => 'Login — ' . $config['app_name'],
            'description' => '',
            'error'       => 'Invalid email or password.',
        ]);
        return;
    }

    Auth::login($user);
    redirect('/');
});

// ── Register ──────────────────────────────────────────────────────────────────
$router->add('GET', '/register', function() use ($config) {
    if (Auth::check()) redirect('/');
    if (!$config['allow_registration']) { http_response_code(403); render('404', ['title' => 'Registration Closed', 'description' => '']); return; }
    render('register', ['title' => 'Register — ' . $config['app_name'], 'description' => '', 'noindex' => true]);
});

$router->add('POST', '/register', function() use ($config) {
    if (!$config['allow_registration']) { http_response_code(403); exit('Registration closed.'); }
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $pass     = $_POST['password'] ?? '';
    $errors   = [];

    if (mb_strlen($username) < 3)               $errors[] = 'Username must be at least 3 characters.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (mb_strlen($pass) < 8)                   $errors[] = 'Password must be at least 8 characters.';
    if (DB::one("SELECT id FROM users WHERE username = ?", [$username])) $errors[] = 'Username already taken.';
    if (DB::one("SELECT id FROM users WHERE email = ?",    [$email]))    $errors[] = 'Email already registered.';

    if ($errors) {
        render('register', [
            'title'       => 'Register — ' . $config['app_name'],
            'description' => '',
            'errors'      => $errors,
            'old'         => $_POST,
        ]);
        return;
    }

    $role = DB::one("SELECT COUNT(*) AS c FROM users")['c'] == 0 ? 'admin' : 'user';
    DB::execute(
        "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)",
        [$username, $email, password_hash($pass, PASSWORD_DEFAULT), $role]
    );

    Auth::login(DB::one("SELECT * FROM users WHERE email = ?", [$email]));
    redirect('/');
});

// ── Logout ────────────────────────────────────────────────────────────────────
$router->add('GET', '/logout', function() {
    Auth::logout();
    redirect('/');
});

// ── Admin ─────────────────────────────────────────────────────────────────────
$router->add('GET', '/cp', function() use ($config) {
    Auth::requireAdmin();
    $sections = DB::all("SELECT * FROM sections ORDER BY display_order, name");
    $users    = DB::all("SELECT id, username, email, role, source, created_at FROM users ORDER BY created_at ASC");
    $api_key  = DB::one("SELECT value FROM settings WHERE `key` = 'api_key'")['value'] ?? '';
    render('admin', [
        'sections'    => $sections,
        'users'       => $users,
        'api_key'     => $api_key,
        'title'       => 'Admin — ' . $config['app_name'],
        'description' => '',
    ]);
});

$router->add('POST', '/cp/settings', function() use ($config) {
    Auth::requireAdmin();
    csrf_verify();
    $updates = [
        'forum_name'         => trim($_POST['forum_name'] ?? '') ?: $config['app_name'],
        'forum_description'  => trim($_POST['forum_description'] ?? ''),
        'allow_registration' => isset($_POST['allow_registration']) ? '1' : '0',
        'posts_per_page'     => (string)max(5, min(100, (int)($_POST['posts_per_page'] ?? 20))),
    ];
    foreach ($updates as $k => $v) {
        DB::execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [$v, $k]);
    }
    redirect('/cp');
});

$router->add('POST', '/cp/api/regenerate', function() {
    Auth::requireAdmin();
    csrf_verify();
    DB::execute("UPDATE settings SET value = ? WHERE `key` = 'api_key'", [API::generateKey()]);
    redirect('/cp');
});

$router->add('POST', '/cp/update', function() use ($config) {
    Auth::requireAdmin();
    csrf_verify();

    $data = Updater::fetch(Updater::ZIP_URL);
    $ok   = Updater::applyZip($data);
    if ($ok) file_put_contents(ROOT . '/.update_check', time());

    $sections = DB::all("SELECT * FROM sections ORDER BY display_order, name");
    render('admin', [
        'sections'    => $sections,
        'update_msg'  => $ok ? 'Updated successfully.' : 'Update failed — check update.log.',
        'title'       => 'Admin — ' . $config['app_name'],
        'description' => '',
    ]);
});

$router->add('POST', '/cp/section', function() {
    Auth::requireAdmin();
    csrf_verify();
    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $order = (int)($_POST['display_order'] ?? 0);
    if (!$name) { redirect('/cp'); return; }

    $reserved = ['login','register','logout','new-thread','reply','upload','admin','cp','webhook',
                 'api','sitemap','robots','assets','uploads','install','config'];
    $candidate = unique_slug('sections', $name);
    if (in_array($candidate, $reserved, true)) { redirect('/cp'); return; }

    DB::execute(
        "INSERT INTO sections (name, slug, description, display_order) VALUES (?, ?, ?, ?)",
        [$name, $candidate, $desc ?: null, $order]
    );
    redirect('/cp');
});

$router->add('POST', '/cp/section/delete', function() {
    Auth::requireAdmin();
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    if ($id) DB::execute("DELETE FROM sections WHERE id = ?", [$id]);
    redirect('/cp');
});

$router->add('POST', '/cp/user/role', function() {
    Auth::requireAdmin();
    csrf_verify();
    $id   = (int)($_POST['id'] ?? 0);
    $role = $_POST['role'] ?? 'user';
    if (!in_array($role, ['user', 'moderator'])) $role = 'user'; // admin can't be set here
    if ($id) DB::execute("UPDATE users SET role = ? WHERE id = ? AND role != 'admin'", [$role, $id]);
    redirect('/cp');
});

// ── Mod actions ───────────────────────────────────────────────────────────────
$router->add('POST', '/reply/delete', function() {
    Auth::requireMod();
    csrf_verify();
    $id    = (int)($_POST['id'] ?? 0);
    $reply = $id ? DB::one("
        SELECT r.*, t.slug AS thread_slug, t.id AS thread_id, s.slug AS section_slug, u.role AS author_role
        FROM replies r
        JOIN threads t ON t.id = r.thread_id
        JOIN sections s ON s.id = t.section_id
        JOIN users u ON u.id = r.user_id
        WHERE r.id = ?
    ", [$id]) : null;
    if (!$reply) { redirect($_SERVER['HTTP_REFERER'] ?? '/'); return; }
    if (!Auth::isAdmin() && $reply['author_role'] === 'admin') { redirect($_SERVER['HTTP_REFERER'] ?? '/'); return; }

    DB::execute("DELETE FROM replies WHERE id = ?", [$id]);
    DB::execute("
        UPDATE threads SET updated_at = updated_at,
               reply_count = (SELECT COUNT(*) FROM replies r WHERE r.thread_id = threads.id AND r.created_at <= NOW())
        WHERE id = ?
    ", [$reply['thread_id']]);

    redirect('/' . $reply['section_slug'] . '/' . $reply['thread_slug']);
});

$router->add('POST', '/thread/delete', function() {
    Auth::requireMod();
    csrf_verify();
    $id     = (int)($_POST['id'] ?? 0);
    $thread = $id ? DB::one("
        SELECT t.*, s.slug AS section_slug, u.role AS author_role
        FROM threads t
        JOIN sections s ON s.id = t.section_id
        JOIN users u ON u.id = t.user_id
        WHERE t.id = ?
    ", [$id]) : null;
    if (!$thread) { redirect($_SERVER['HTTP_REFERER'] ?? '/'); return; }
    if (!Auth::isAdmin() && $thread['author_role'] === 'admin') { redirect($_SERVER['HTTP_REFERER'] ?? '/'); return; }

    DB::execute("DELETE FROM threads WHERE id = ?", [$id]);
    redirect('/' . $thread['section_slug']);
});

// ── API ───────────────────────────────────────────────────────────────────────
$router->add('GET', '/api/sections', function() {
    API::auth();
    $sections = DB::all("
        SELECT s.id, s.name, s.slug, s.description, s.display_order,
               SUM(t.created_at <= NOW()) AS thread_count,
               SUM(t.created_at >  NOW()) AS scheduled_threads,
               MAX(IF(t.created_at <= NOW(), t.updated_at, NULL)) AS last_activity
        FROM sections s
        LEFT JOIN threads t ON t.section_id = s.id
        GROUP BY s.id
        ORDER BY s.display_order, s.name
    ");
    foreach ($sections as &$s) { $s['thread_count'] = (int)$s['thread_count']; $s['scheduled_threads'] = (int)$s['scheduled_threads']; }
    unset($s);
    API::respond(['sections' => $sections, 'server_time' => API::serverTime()]);
});

$router->add('GET', '/api/users', function() {
    API::auth();
    [$limit, $offset] = API::paging();
    $where  = '';
    $params = [];
    if (!empty($_GET['role'])) { $where = 'WHERE u.role = ?'; $params[] = $_GET['role']; }

    $total = (int)DB::one("SELECT COUNT(*) AS c FROM users u $where", $params)['c'];
    $users = DB::all("
        SELECT u.id, u.username, u.email, u.role, u.source, u.created_at,
               (SELECT COUNT(*) FROM threads t WHERE t.user_id = u.id) AS thread_count,
               (SELECT COUNT(*) FROM replies r WHERE r.user_id = u.id) AS reply_count
        FROM users u $where
        ORDER BY u.id ASC
        LIMIT ? OFFSET ?
    ", [...$params, $limit, $offset]);
    API::respond(['users' => $users, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
});

$router->add('GET', '/api/threads', function() use ($config) {
    API::auth();
    [$limit, $offset] = API::paging();
    $where  = [];
    $params = [];
    if (!empty($_GET['section'])) { $where[] = 's.slug = ?';    $params[] = $_GET['section']; }
    if (!empty($_GET['user_id'])) { $where[] = 't.user_id = ?'; $params[] = (int)$_GET['user_id']; }
    $sched = $_GET['scheduled'] ?? 'include';
    if ($sched === 'only')    $where[] = 't.created_at > NOW()';
    if ($sched === 'exclude') $where[] = 't.created_at <= NOW()';
    $where = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $order = match ($_GET['sort'] ?? 'updated') {
        'created' => 't.created_at DESC',
        'oldest'  => 't.created_at ASC',
        'replies' => 't.reply_count DESC, t.updated_at DESC',
        default   => 't.updated_at DESC',
    };

    $total   = (int)DB::one("SELECT COUNT(*) AS c FROM threads t JOIN sections s ON s.id = t.section_id $where", $params)['c'];
    $threads = DB::all("
        SELECT t.id, t.title, t.slug, t.body, t.user_id, u.username AS author, t.source,
               s.slug AS section, t.reply_count, t.view_count, t.created_at, t.updated_at,
               (t.created_at > NOW()) AS scheduled,
               (SELECT COUNT(*) FROM replies r WHERE r.thread_id = t.id AND r.created_at > NOW()) AS scheduled_replies,
               (SELECT MAX(r.created_at) FROM replies r WHERE r.thread_id = t.id) AS last_reply_at
        FROM threads t
        JOIN sections s ON s.id = t.section_id
        JOIN users u    ON u.id = t.user_id
        $where
        ORDER BY $order
        LIMIT ? OFFSET ?
    ", [...$params, $limit, $offset]);
    foreach ($threads as &$t) {
        $t['scheduled']         = (bool)$t['scheduled'];
        $t['scheduled_replies'] = (int)$t['scheduled_replies'];
        $t['excerpt'] = excerpt($t['body'], 300);
        $t['url']     = $config['app_url'] . '/' . $t['section'] . '/' . $t['slug'];
        unset($t['body']);
    }
    unset($t);
    API::respond(['threads' => $threads, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
});

$router->add('GET', '/api/threads/{id}', function(array $p) use ($config) {
    API::auth();
    $thread = ctype_digit($p['id']) ? DB::one("
        SELECT t.id, t.title, t.slug, t.body, t.image, t.user_id, u.username AS author, t.source,
               s.slug AS section, t.reply_count, t.view_count, t.created_at, t.updated_at,
               (t.created_at > NOW()) AS scheduled
        FROM threads t
        JOIN sections s ON s.id = t.section_id
        JOIN users u    ON u.id = t.user_id
        WHERE t.id = ?
    ", [(int)$p['id']]) : null;
    if (!$thread) API::respond(['error' => 'Thread not found.'], 404);

    $thread['url']       = $config['app_url'] . '/' . $thread['section'] . '/' . $thread['slug'];
    $thread['scheduled'] = (bool)$thread['scheduled'];
    $replies = DB::all("
        SELECT r.id, r.user_id, u.username AS author, r.body, r.image, r.created_at, r.source,
               (r.created_at > NOW()) AS scheduled
        FROM replies r JOIN users u ON u.id = r.user_id
        WHERE r.thread_id = ?
        ORDER BY r.created_at ASC, r.id ASC
    ", [$thread['id']]);
    foreach ($replies as &$r) $r['scheduled'] = (bool)$r['scheduled'];
    unset($r);
    API::respond(['thread' => $thread, 'replies' => $replies]);
});

$router->add('POST', '/api/users', function() {
    API::auth();
    $b = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $username = trim($b['username'] ?? '');
    $email    = trim($b['email']    ?? '');
    $pass     = $b['password']      ?? '';
    $role     = $b['role']          ?? 'user';
    $created  = API::timestamp($b['created_at'] ?? null);

    if (!in_array($role, ['user','moderator','admin'], true)) $role = 'user';
    if (mb_strlen($username) < 3)                        API::respond(['error' => 'Username must be at least 3 characters.'], 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))      API::respond(['error' => 'Invalid email address.'], 422);
    if (mb_strlen($pass) < 8)                            API::respond(['error' => 'Password must be at least 8 characters.'], 422);
    if (DB::one("SELECT id FROM users WHERE username = ?", [$username])) API::respond(['error' => 'Username already taken.'], 422);
    if (DB::one("SELECT id FROM users WHERE email = ?",    [$email]))    API::respond(['error' => 'Email already registered.'], 422);

    DB::execute(
        "INSERT INTO users (username, email, password_hash, role, created_at, source) VALUES (?, ?, ?, ?, COALESCE(?, NOW()), 'api')",
        [$username, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $created]
    );
    $user = DB::one("SELECT id, username, email, role, created_at FROM users WHERE id = ?", [DB::lastId()]);
    API::respond(['user' => $user], 201);
});

// Update a user's username and/or email. Posts reference users by id, so a
// rename shows everywhere immediately.
$router->add('POST', '/api/users/{id}', function(array $p) {
    API::auth();
    $id = ctype_digit($p['id']) ? (int)$p['id'] : 0;
    if (!$id || !DB::one("SELECT id FROM users WHERE id = ?", [$id])) API::respond(['error' => 'User not found.'], 404);
    $b = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    if (isset($b['username'])) {
        $username = trim($b['username']);
        if (mb_strlen($username) < 3 || mb_strlen($username) > 50) API::respond(['error' => 'Username must be 3-50 characters.'], 422);
        if (DB::one("SELECT id FROM users WHERE username = ? AND id != ?", [$username, $id])) API::respond(['error' => 'Username already taken.'], 422);
        DB::execute("UPDATE users SET username = ? WHERE id = ?", [$username, $id]);
    }
    if (isset($b['email'])) {
        $email = trim($b['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) API::respond(['error' => 'Invalid email address.'], 422);
        if (DB::one("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $id])) API::respond(['error' => 'Email already registered.'], 422);
        DB::execute("UPDATE users SET email = ? WHERE id = ?", [$email, $id]);
    }
    // Backfill: mark an account (and, with apply_to_posts, everything it posted) as web/api.
    if (isset($b['source'])) {
        if (!in_array($b['source'], ['web', 'api'], true)) API::respond(['error' => "source must be 'web' or 'api'."], 422);
        DB::execute("UPDATE users SET source = ? WHERE id = ?", [$b['source'], $id]);
        if (!empty($b['apply_to_posts'])) {
            DB::execute("UPDATE threads SET source = ?, updated_at = updated_at WHERE user_id = ?", [$b['source'], $id]);
            DB::execute("UPDATE replies SET source = ? WHERE user_id = ?", [$b['source'], $id]);
        }
    }
    API::respond(['user' => DB::one("SELECT id, username, email, role, source, created_at FROM users WHERE id = ?", [$id])]);
});

$router->add('POST', '/api/threads', function() use ($config) {
    API::auth();
    $b = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $title   = trim($b['title']   ?? '');
    $body    = trim($b['body']    ?? '');
    $user_id = (int)($b['user_id'] ?? 0);
    $created = API::timestamp($b['created_at'] ?? null);

    if (!$title) API::respond(['error' => 'title is required.'], 422);
    if (!$body)  API::respond(['error' => 'body is required.'], 422);

    $section = isset($b['section'])
        ? DB::one("SELECT * FROM sections WHERE slug = ?", [$b['section']])
        : (isset($b['section_id']) ? DB::one("SELECT * FROM sections WHERE id = ?", [(int)$b['section_id']]) : null);
    if (!$section) API::respond(['error' => 'Section not found. Pass section (slug) or section_id.'], 422);

    if ($user_id) {
        $user = DB::one("SELECT id FROM users WHERE id = ?", [$user_id]);
        if (!$user) API::respond(['error' => 'User not found.'], 404);
    } else {
        $user = DB::one("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    }

    $slug = unique_slug('threads', $title, 'section_id', $section['id']);
    DB::execute(
        "INSERT INTO threads (section_id, user_id, title, slug, body, created_at, updated_at, source)
         VALUES (?, ?, ?, ?, ?, COALESCE(?, NOW()), COALESCE(?, NOW()), 'api')",
        [$section['id'], $user['id'], $title, $slug, $body, $created, $created]
    );
    $thread = DB::one("SELECT id, title, slug, created_at, updated_at, (created_at > NOW()) AS scheduled FROM threads WHERE id = ?", [DB::lastId()]);
    $thread['scheduled'] = (bool)$thread['scheduled'];
    API::respond(['thread' => $thread + [
        'url'     => $config['app_url'] . '/' . $section['slug'] . '/' . $thread['slug'],
        'section' => $section['slug'],
    ]], 201);
});

$router->add('POST', '/api/replies', function() use ($config) {
    API::auth();
    $b = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $thread_id = (int)($b['thread_id'] ?? 0);
    $body      = trim($b['body'] ?? '');
    $user_id   = (int)($b['user_id'] ?? 0);
    $created   = API::timestamp($b['created_at'] ?? null);

    if (!$body) API::respond(['error' => 'body is required.'], 422);

    $thread = $thread_id ? DB::one("
        SELECT t.*, s.slug AS section_slug
        FROM threads t JOIN sections s ON s.id = t.section_id
        WHERE t.id = ?
    ", [$thread_id]) : null;
    if (!$thread) API::respond(['error' => 'Thread not found. Pass thread_id.'], 422);
    if ($created && strtotime($created) < strtotime($thread['created_at'])) {
        API::respond(['error' => 'created_at cannot be earlier than the thread (' . $thread['created_at'] . ').'], 422);
    }

    if ($user_id) {
        $user = DB::one("SELECT id FROM users WHERE id = ?", [$user_id]);
        if (!$user) API::respond(['error' => 'User not found.'], 404);
    } else {
        $user = DB::one("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    }

    DB::execute(
        "INSERT INTO replies (thread_id, user_id, body, image, created_at, source) VALUES (?, ?, ?, ?, COALESCE(?, NOW()), 'api')",
        [$thread_id, $user['id'], $body, null, $created]
    );
    $reply_id = DB::lastId();   // capture before the UPDATE; MySQL resets lastInsertId() after a non-INSERT
    // Activity time only moves forward: a backdated reply inserted after newer
    // ones must not drag the thread's "Updated" time backwards. A scheduled
    // (future) reply leaves the thread alone; publish_due() counts it once live.
    DB::execute(
        "UPDATE threads SET reply_count = reply_count + 1, updated_at = GREATEST(updated_at, COALESCE(?, NOW()))
         WHERE id = ? AND COALESCE(?, NOW()) <= NOW()",
        [$created, $thread_id, $created]
    );

    $reply = DB::one("SELECT id, thread_id, user_id, created_at, (created_at > NOW()) AS scheduled FROM replies WHERE id = ?", [$reply_id]);
    $reply['scheduled'] = (bool)$reply['scheduled'];
    API::respond(['reply' => $reply + [
        'url' => $config['app_url'] . '/' . $thread['section_slug'] . '/' . $thread['slug'],
    ]], 201);
});

// Edit a thread's title/body or a reply's body. Doesn't bump updated_at or
// change the slug, so the thread keeps its place and URL.
$router->add('POST', '/api/threads/{id}', function(array $p) {
    API::auth();
    $id = ctype_digit($p['id']) ? (int)$p['id'] : 0;
    if (!$id || !DB::one("SELECT id FROM threads WHERE id = ?", [$id])) API::respond(['error' => 'Thread not found.'], 404);
    $b = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    foreach (['title' => 255, 'body' => 65535] as $field => $max) {
        if (!isset($b[$field])) continue;
        $v = trim($b[$field]);
        if ($v === '' || strlen($v) > $max) API::respond(['error' => "Invalid $field."], 422);
        DB::execute("UPDATE threads SET $field = ?, updated_at = updated_at WHERE id = ?", [$v, $id]);
    }
    API::respond(['thread' => DB::one("SELECT id, title, body, created_at, updated_at FROM threads WHERE id = ?", [$id])]);
});

$router->add('POST', '/api/replies/{id}', function(array $p) {
    API::auth();
    $id = ctype_digit($p['id']) ? (int)$p['id'] : 0;
    if (!$id || !DB::one("SELECT id FROM replies WHERE id = ?", [$id])) API::respond(['error' => 'Reply not found.'], 404);
    $b = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $body = trim($b['body'] ?? '');
    if ($body === '') API::respond(['error' => 'Body is required.'], 422);
    DB::execute("UPDATE replies SET body = ? WHERE id = ?", [$body, $id]);
    API::respond(['reply' => DB::one("SELECT id, thread_id, body, created_at FROM replies WHERE id = ?", [$id])]);
});

$router->add('POST', '/api/threads/{id}/delete', function(array $p) {
    API::auth();
    $id = ctype_digit($p['id']) ? (int)$p['id'] : 0;
    if (!$id || !DB::one("SELECT id FROM threads WHERE id = ?", [$id])) API::respond(['error' => 'Thread not found.'], 404);
    DB::execute("DELETE FROM threads WHERE id = ?", [$id]);   // replies cascade
    API::respond(['deleted' => ['thread_id' => $id]]);
});

$router->add('POST', '/api/replies/{id}/delete', function(array $p) {
    API::auth();
    $id    = ctype_digit($p['id']) ? (int)$p['id'] : 0;
    $reply = $id ? DB::one("SELECT id, thread_id FROM replies WHERE id = ?", [$id]) : null;
    if (!$reply) API::respond(['error' => 'Reply not found.'], 404);
    DB::execute("DELETE FROM replies WHERE id = ?", [$id]);
    DB::execute("
        UPDATE threads SET updated_at = updated_at,
               reply_count = (SELECT COUNT(*) FROM replies r WHERE r.thread_id = threads.id AND r.created_at <= NOW())
        WHERE id = ?
    ", [$reply['thread_id']]);
    API::respond(['deleted' => ['reply_id' => $id, 'thread_id' => (int)$reply['thread_id']]]);
});

$router->add('POST', '/api/update', function() {
    API::auth();
    $before = Updater::currentVersion();
    $ok     = Updater::applyZip(Updater::fetch(Updater::ZIP_URL));
    if ($ok) file_put_contents(ROOT . '/.update_check', time());
    $after  = Updater::currentVersion();
    API::respond($ok
        ? ['success' => true,  'version' => $after, 'previous' => $before]
        : ['success' => false, 'error'   => 'Update failed. Check update.log.']
    );
});

// ── Sitemap ───────────────────────────────────────────────────────────────────
$router->add('GET', '/sitemap.xml', function() use ($config) {
    $sections = DB::all("SELECT slug FROM sections");
    $threads  = DB::all("
        SELECT t.slug, t.updated_at, s.slug AS section_slug
        FROM threads t JOIN sections s ON s.id = t.section_id
        WHERE t.created_at <= NOW()
        ORDER BY t.updated_at DESC
        LIMIT 50000
    ");

    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo '<url><loc>' . h($config['app_url']) . '/</loc></url>' . "\n";
    foreach ($sections as $s) {
        echo '<url><loc>' . h($config['app_url'] . '/' . $s['slug']) . '</loc></url>' . "\n";
    }
    foreach ($threads as $t) {
        $loc = h($config['app_url'] . '/' . $t['section_slug'] . '/' . $t['slug']);
        $mod = date('Y-m-d', strtotime($t['updated_at']));
        echo "<url><loc>{$loc}</loc><lastmod>{$mod}</lastmod></url>\n";
    }
    echo '</urlset>';
    exit;
});

// ── Robots ────────────────────────────────────────────────────────────────────
$router->add('GET', '/robots.txt', function() use ($config) {
    header('Content-Type: text/plain');
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "Disallow: /login\n";
    echo "Disallow: /register\n";
    echo "Disallow: /logout\n";
    echo "Disallow: /new-thread\n";
    echo "Disallow: /reply\n";
    echo "Disallow: /cp\n";
    echo "Disallow: /admin\n";
    echo "Disallow: /webhook\n";
    echo "\nSitemap: " . $config['app_url'] . "/sitemap.xml\n";
    exit;
});

// ── Image upload (AJAX from BB toolbar) ───────────────────────────────────────
$router->add('POST', '/upload', function() {
    Auth::require();
    csrf_verify();
    $path = !empty($_FILES['image']['name']) ? upload_image($_FILES['image']) : null;
    header('Content-Type: application/json');
    if (!$path) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid or missing image.']);
    } else {
        echo json_encode(['url' => '/uploads/' . $path]);
    }
    exit;
});

// ── Section ───────────────────────────────────────────────────────────────────
$router->add('GET', '/{slug}', function(array $p) use ($config, $PER_PAGE) {
    $section = DB::one("SELECT * FROM sections WHERE slug = ?", [$p['slug']]);
    if (!$section) { http_response_code(404); render('404', ['title' => 'Not Found', 'description' => '']); return; }

    $page  = max(1, (int)($_GET['page'] ?? 1));
    $total = (int)DB::one("SELECT COUNT(*) AS c FROM threads WHERE section_id = ? AND created_at <= NOW()", [$section['id']])['c'];
    $pg    = paginate($total, $PER_PAGE, $page);

    $threads = DB::all("
        SELECT t.*, u.username
        FROM threads t
        JOIN users u ON u.id = t.user_id
        WHERE t.section_id = ? AND t.created_at <= NOW()
        ORDER BY t.updated_at DESC
        LIMIT ? OFFSET ?
    ", [$section['id'], $PER_PAGE, $pg['offset']]);

    $base  = $config['app_url'] . '/' . $section['slug'];
    $items = [];
    foreach ($threads as $i => $t) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pg['offset'] + $i + 1,
            'url'      => $base . '/' . $t['slug'],
            'name'     => $t['title'],
        ];
    }
    render('section', [
        'section'          => $section,
        'threads'          => $threads,
        'pg'               => $pg,
        'title'            => $section['name'] . ' — ' . $config['app_name'],
        'description'      => excerpt($section['description'] ?? $section['name']),
        'canonical'        => $base . ($page > 1 ? '?page=' . $page : ''),
        'prev_url'         => $pg['has_prev'] ? $base . '?page=' . ($page - 1) : null,
        'next_url'         => $pg['has_next'] ? $base . '?page=' . ($page + 1) : null,
        'schema'           => json_encode([
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => $section['name'],
            'itemListElement' => $items,
        ]),
        'breadcrumb_schema' => json_encode([
            '@context' => 'https://schema.org',
            '@type'    => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $config['app_url'] . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $section['name']],
            ],
        ]),
    ]);
});

// ── Thread ────────────────────────────────────────────────────────────────────
$router->add('GET', '/{section}/{thread}', function(array $p) use ($config) {
    $section = DB::one("SELECT * FROM sections WHERE slug = ?", [$p['section']]);
    if (!$section) { http_response_code(404); render('404', ['title' => 'Not Found', 'description' => '']); return; }

    $thread = DB::one("
        SELECT t.*, u.username AS author, u.role AS author_role
        FROM threads t JOIN users u ON u.id = t.user_id
        WHERE t.section_id = ? AND t.slug = ? AND t.created_at <= NOW()
    ", [$section['id'], $p['thread']]);
    if (!$thread) { http_response_code(404); render('404', ['title' => 'Not Found', 'description' => '']); return; }

    // Set updated_at to its own value so the ON UPDATE CURRENT_TIMESTAMP does not
    // fire — a page view must not count as thread activity in the "Updated" column.
    DB::execute("UPDATE threads SET view_count = view_count + 1, updated_at = updated_at WHERE id = ?", [$thread['id']]);

    $page  = max(1, (int)($_GET['page'] ?? 1));
    $total = (int)DB::one("SELECT COUNT(*) AS c FROM replies WHERE thread_id = ? AND created_at <= NOW()", [$thread['id']])['c'];
    $thread['reply_count'] = $total;   // exact even if publish_due() hasn't caught up yet
    $pg    = paginate($total, 30, $page);

    $replies = DB::all("
        SELECT r.*, u.username AS author, u.role AS author_role
        FROM replies r JOIN users u ON u.id = r.user_id
        WHERE r.thread_id = ? AND r.created_at <= NOW()
        ORDER BY r.created_at ASC
        LIMIT ? OFFSET ?
    ", [$thread['id'], 30, $pg['offset']]);

    $base   = $config['app_url'] . '/' . $section['slug'] . '/' . $thread['slug'];
    $schema = json_encode([
        '@context'  => 'https://schema.org',
        '@type'     => 'DiscussionForumPosting',
        'headline'  => $thread['title'],
        'text'      => excerpt($thread['body'], 500),
        'author'    => ['@type' => 'Person', 'name' => $thread['author']],
        'datePublished' => $thread['created_at'],
        'dateModified'  => $thread['updated_at'],
        'url'       => $base,
        'interactionStatistic' => [
            '@type'                => 'InteractionCounter',
            'interactionType'      => 'https://schema.org/CommentAction',
            'userInteractionCount' => $thread['reply_count'],
        ],
    ]);

    render('thread', [
        'section'          => $section,
        'thread'           => $thread,
        'replies'          => $replies,
        'pg'               => $pg,
        'title'            => $thread['title'] . ' — ' . $section['name'] . ' — ' . $config['app_name'],
        'description'      => excerpt($thread['body']),
        'canonical'        => $base . ($page > 1 ? '?page=' . $page : ''),
        'prev_url'         => $pg['has_prev'] ? $base . '?page=' . ($page - 1) : null,
        'next_url'         => $pg['has_next'] ? $base . '?page=' . ($page + 1) : null,
        'og_type'          => 'article',
        'schema'           => $schema,
        'breadcrumb_schema' => json_encode([
            '@context' => 'https://schema.org',
            '@type'    => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',              'item' => $config['app_url'] . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $section['name'],    'item' => $config['app_url'] . '/' . $section['slug']],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $thread['title']],
            ],
        ]),
    ]);
});

// ── Dispatch ──────────────────────────────────────────────────────────────────
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
