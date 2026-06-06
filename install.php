<?php
// Redirect away if already installed
if (file_exists(__DIR__ . '/config.php') && !file_exists(__FILE__)) {
    header('Location: /');
    exit;
}

$errors = [];
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$guessed_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$old    = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Collect & validate ────────────────────────────────────────────────────
    $db_host   = trim($_POST['db_host']   ?? 'localhost');
    $db_name   = trim($_POST['db_name']   ?? '');
    $db_user   = trim($_POST['db_user']   ?? '');
    $db_pass   = $_POST['db_pass']        ?? '';
    $app_name  = trim($_POST['app_name']  ?? 'Forum');
    $app_url   = rtrim(trim($_POST['app_url'] ?? ''), '/');
    $wh_secret = trim($_POST['wh_secret'] ?? '');
    $username  = trim($_POST['username']  ?? '');
    $email     = trim($_POST['email']     ?? '');
    $pass      = $_POST['password']       ?? '';

    if (!$db_host)  $errors[] = 'Database host is required.';
    if (!$db_name)  $errors[] = 'Database name is required.';
    if (!$db_user)  $errors[] = 'Database username is required.';
    if (!$app_url || !filter_var($app_url, FILTER_VALIDATE_URL)) $errors[] = 'A valid App URL is required (e.g. https://example.com).';
    if (mb_strlen($username) < 3)  $errors[] = 'Admin username must be at least 3 characters.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid admin email address.';
    if (mb_strlen($pass) < 8)      $errors[] = 'Admin password must be at least 8 characters.';

    if (empty($errors)) {
        try {
            // ── Test DB connection & create database ──────────────────────────
            $pdo = new PDO(
                "mysql:host={$db_host};charset=utf8mb4",
                $db_user, $db_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$db_name}`");

            // ── Create tables ─────────────────────────────────────────────────
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                username      VARCHAR(50)   NOT NULL UNIQUE,
                email         VARCHAR(255)  NOT NULL UNIQUE,
                password_hash VARCHAR(255)  NOT NULL,
                role          ENUM('user','moderator','admin') NOT NULL DEFAULT 'user',
                created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS sections (
                id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                name          VARCHAR(100)  NOT NULL,
                slug          VARCHAR(100)  NOT NULL UNIQUE,
                description   TEXT,
                display_order SMALLINT      NOT NULL DEFAULT 0,
                created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS threads (
                id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                section_id  INT UNSIGNED  NOT NULL,
                user_id     INT UNSIGNED  NOT NULL,
                title       VARCHAR(255)  NOT NULL,
                slug        VARCHAR(255)  NOT NULL,
                body        TEXT          NOT NULL,
                image       VARCHAR(255)  DEFAULT NULL,
                reply_count INT UNSIGNED  NOT NULL DEFAULT 0,
                view_count  INT UNSIGNED  NOT NULL DEFAULT 0,
                created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
                UNIQUE KEY section_thread (section_id, slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS replies (
                id         INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                thread_id  INT UNSIGNED  NOT NULL,
                user_id    INT UNSIGNED  NOT NULL,
                body       TEXT          NOT NULL,
                image      VARCHAR(255)  DEFAULT NULL,
                created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // ── Create admin account ──────────────────────────────────────────
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')"
            );
            $stmt->execute([$username, $email, password_hash($pass, PASSWORD_DEFAULT)]);

            // ── Write config.php ──────────────────────────────────────────────
            $cfg_php = '<?php' . "\n" . 'return ' . var_export([
                'db_host'        => $db_host,
                'db_name'        => $db_name,
                'db_user'        => $db_user,
                'db_pass'        => $db_pass,
                'db_charset'     => 'utf8mb4',
                'webhook_secret' => $wh_secret,
                'deploy_branch'  => 'main',
                'app_name'       => $app_name ?: 'Forum',
                'app_url'        => $app_url,
            ], true) . ";\n";

            if (file_put_contents(__DIR__ . '/config.php', $cfg_php) === false) {
                $errors[] = 'Could not write config.php — check directory permissions.';
            }

        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        // ── Create uploads directory with PHP execution blocked ────────────────
        $uploads = __DIR__ . '/uploads';
        if (!is_dir($uploads)) mkdir($uploads, 0755);
        file_put_contents($uploads . '/.htaccess', "php_flag engine off\nOptions -ExecCGI\n");

        // ── Self-destruct & redirect ──────────────────────────────────────────
        unlink(__FILE__);
        header('Location: /');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forum Setup</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body  { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
          font-size: 15px; background: #eaecef; color: #222; }
  .wrap { max-width: 520px; margin: 48px auto; padding: 0 16px 48px; }
  h1    { font-size: 1.5rem; margin-bottom: 6px; }
  .sub  { color: #666; margin-bottom: 28px; font-size: 0.9rem; }
  h2    { font-size: 1rem; margin: 28px 0 14px; padding-top: 24px;
          border-top: 1px solid #ddd; color: #333; }
  h2:first-of-type { border-top: none; padding-top: 0; margin-top: 0; }
  label { display: block; font-size: 0.875rem; font-weight: 600; margin-bottom: 4px; }
  label small { font-weight: normal; color: #888; }
  input { display: block; width: 100%; padding: 8px 10px; margin-bottom: 14px;
          border: 1px solid #ccc; border-radius: 4px; font: inherit;
          background: #fff; transition: border-color .15s; }
  input:focus { outline: none; border-color: #1a3a5c; }
  button { display: block; width: 100%; padding: 11px; margin-top: 8px;
           background: #1a3a5c; color: #fff; border: none; border-radius: 4px;
           cursor: pointer; font: inherit; font-size: 1rem; font-weight: 600; }
  button:hover { background: #14304e; }
  .card   { background: #fff; border-radius: 8px; padding: 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,.1); }
  .errors { background: #f8d7da; color: #721c24; border-radius: 4px;
            padding: 10px 16px 10px 28px; margin-bottom: 20px; font-size: 0.875rem; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Forum Setup</h1>
  <p class="sub">Fill in the fields below to install your forum. This page deletes itself when done.</p>

  <?php if (!empty($errors)): ?>
  <ul class="errors">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach ?>
  </ul>
  <?php endif ?>

  <form method="post" class="card">

    <h2>Database</h2>
    <label>Host <small>(usually localhost)</small></label>
    <input type="text" name="db_host" value="<?= htmlspecialchars($old['db_host'] ?? 'localhost') ?>" required>

    <label>Database name</label>
    <input type="text" name="db_name" value="<?= htmlspecialchars($old['db_name'] ?? '') ?>" required autofocus>

    <label>Username</label>
    <input type="text" name="db_user" value="<?= htmlspecialchars($old['db_user'] ?? '') ?>" required autocomplete="off">

    <label>Password</label>
    <input type="password" name="db_pass" value="<?= htmlspecialchars($old['db_pass'] ?? '') ?>" autocomplete="new-password">

    <h2>Forum settings</h2>
    <label>Forum name</label>
    <input type="text" name="app_name" value="<?= htmlspecialchars($old['app_name'] ?? 'Forum') ?>" required maxlength="100">

    <label>Site URL <small>(no trailing slash, e.g. https://example.com)</small></label>
    <input type="url" name="app_url" value="<?= htmlspecialchars($old['app_url'] ?? $guessed_url) ?>" required placeholder="https://example.com">

    <label>Webhook secret <small>(optional — set this in GitHub repo Settings → Webhooks too)</small></label>
    <input type="text" name="wh_secret" value="<?= htmlspecialchars($old['wh_secret'] ?? '') ?>" autocomplete="off">

    <h2>Admin account</h2>
    <label>Username</label>
    <input type="text" name="username" value="<?= htmlspecialchars($old['username'] ?? '') ?>" required minlength="3" maxlength="50" autocomplete="off">

    <label>Email</label>
    <input type="email" name="email" value="<?= htmlspecialchars($old['email'] ?? '') ?>" required autocomplete="off">

    <label>Password <small>(min 8 characters)</small></label>
    <input type="password" name="password" required minlength="8" autocomplete="new-password">

    <button type="submit">Install Forum &rarr;</button>
  </form>
</div>
</body>
</html>
