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

$router   = new Router();
$PER_PAGE = 20;

// ── Home ──────────────────────────────────────────────────────────────────────
$router->add('GET', '/', function() use ($config) {
    $sections = DB::all("
        SELECT s.*,
               COUNT(DISTINCT t.id) AS thread_count,
               MAX(t.updated_at)    AS last_activity
        FROM sections s
        LEFT JOIN threads t ON t.section_id = s.id
        GROUP BY s.id
        ORDER BY s.display_order, s.name
    ");
    render('home', [
        'sections'    => $sections,
        'title'       => $config['app_name'],
        'description' => 'A forum. Join the conversation.',
        'canonical'   => $config['app_url'] . '/',
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
        WHERE t.id = ?
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

    $count     = (int)DB::one("SELECT COUNT(*) AS c FROM replies WHERE thread_id = ?", [$thread_id])['c'];
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
    render('register', ['title' => 'Register — ' . $config['app_name'], 'description' => '', 'noindex' => true]);
});

$router->add('POST', '/register', function() use ($config) {
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
    $users    = DB::all("SELECT id, username, email, role, created_at FROM users ORDER BY created_at ASC");
    $api_key  = DB::one("SELECT value FROM settings WHERE `key` = 'api_key'")['value'] ?? '';
    render('admin', [
        'sections'    => $sections,
        'users'       => $users,
        'api_key'     => $api_key,
        'title'       => 'Admin — ' . $config['app_name'],
        'description' => '',
    ]);
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
        SELECT r.*, t.slug AS thread_slug, t.id AS thread_id, s.slug AS section_slug
        FROM replies r
        JOIN threads t ON t.id = r.thread_id
        JOIN sections s ON s.id = t.section_id
        WHERE r.id = ?
    ", [$id]) : null;
    if (!$reply) { redirect($_SERVER['HTTP_REFERER'] ?? '/'); return; }

    DB::execute("DELETE FROM replies WHERE id = ?", [$id]);
    DB::execute("UPDATE threads SET reply_count = GREATEST(0, reply_count - 1) WHERE id = ?", [$reply['thread_id']]);

    redirect('/' . $reply['section_slug'] . '/' . $reply['thread_slug']);
});

$router->add('POST', '/thread/delete', function() {
    Auth::requireMod();
    csrf_verify();
    $id     = (int)($_POST['id'] ?? 0);
    $thread = $id ? DB::one("
        SELECT t.*, s.slug AS section_slug
        FROM threads t JOIN sections s ON s.id = t.section_id
        WHERE t.id = ?
    ", [$id]) : null;
    if (!$thread) { redirect($_SERVER['HTTP_REFERER'] ?? '/'); return; }

    DB::execute("DELETE FROM threads WHERE id = ?", [$id]);
    redirect('/' . $thread['section_slug']);
});

// ── API ───────────────────────────────────────────────────────────────────────
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
    $total = (int)DB::one("SELECT COUNT(*) AS c FROM threads WHERE section_id = ?", [$section['id']])['c'];
    $pg    = paginate($total, $PER_PAGE, $page);

    $threads = DB::all("
        SELECT t.*, u.username
        FROM threads t
        JOIN users u ON u.id = t.user_id
        WHERE t.section_id = ?
        ORDER BY t.updated_at DESC
        LIMIT ? OFFSET ?
    ", [$section['id'], $PER_PAGE, $pg['offset']]);

    $base = $config['app_url'] . '/' . $section['slug'];
    render('section', [
        'section'          => $section,
        'threads'          => $threads,
        'pg'               => $pg,
        'title'            => $section['name'] . ' — ' . $config['app_name'],
        'description'      => excerpt($section['description'] ?? $section['name']),
        'canonical'        => $base . ($page > 1 ? '?page=' . $page : ''),
        'prev_url'         => $pg['has_prev'] ? $base . '?page=' . ($page - 1) : null,
        'next_url'         => $pg['has_next'] ? $base . '?page=' . ($page + 1) : null,
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
        WHERE t.section_id = ? AND t.slug = ?
    ", [$section['id'], $p['thread']]);
    if (!$thread) { http_response_code(404); render('404', ['title' => 'Not Found', 'description' => '']); return; }

    DB::execute("UPDATE threads SET view_count = view_count + 1 WHERE id = ?", [$thread['id']]);

    $page  = max(1, (int)($_GET['page'] ?? 1));
    $total = (int)DB::one("SELECT COUNT(*) AS c FROM replies WHERE thread_id = ?", [$thread['id']])['c'];
    $pg    = paginate($total, 30, $page);

    $replies = DB::all("
        SELECT r.*, u.username AS author, u.role AS author_role
        FROM replies r JOIN users u ON u.id = r.user_id
        WHERE r.thread_id = ?
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
