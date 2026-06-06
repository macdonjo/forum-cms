<?php
if (!file_exists(__DIR__ . '/config.php')) {
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Setup</title>
    <style>body{font-family:sans-serif;max-width:560px;margin:60px auto;padding:0 20px}code{background:#f0f0f0;padding:2px 6px;border-radius:3px}</style>
    </head><body>
    <h1>Forum Setup</h1>
    <p>No <code>config.php</code> found. Copy <code>config.example.php</code> to <code>config.php</code> and fill in your database credentials, then reload this page.</p>
    </body></html><?php
    exit;
}

$cfg   = require __DIR__ . '/config.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $pass     = $_POST['password'] ?? '';

    if (!$username || !$email || !$pass) {
        $error = 'All fields are required.';
    } elseif (mb_strlen($username) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (mb_strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host={$cfg['db_host']};charset={$cfg['db_charset']}",
                $cfg['db_user'], $cfg['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['db_name']}`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$cfg['db_name']}`");

            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
                username      VARCHAR(50)   NOT NULL UNIQUE,
                email         VARCHAR(255)  NOT NULL UNIQUE,
                password_hash VARCHAR(255)  NOT NULL,
                is_admin      TINYINT(1)    NOT NULL DEFAULT 0,
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

            $stmt = $pdo->prepare(
                "INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, 1)"
            );
            $stmt->execute([$username, $email, password_hash($pass, PASSWORD_DEFAULT)]);

            // Create uploads dir with PHP-execution blocked
            $uploads = __DIR__ . '/uploads';
            if (!is_dir($uploads)) mkdir($uploads, 0755);
            file_put_contents($uploads . '/.htaccess', "php_flag engine off\nOptions -ExecCGI\n");

            unlink(__FILE__);
            header('Location: /');
            exit;

        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Install Forum</title>
<style>
  body  { font-family: sans-serif; max-width: 480px; margin: 60px auto; padding: 0 20px; }
  label { display: block; font-size: .9rem; font-weight: 600; margin-bottom: 4px; }
  input { display: block; width: 100%; padding: 8px 10px; margin-bottom: 14px;
          border: 1px solid #ccc; border-radius: 4px; font: inherit; box-sizing: border-box; }
  button { padding: 10px 24px; background: #1a3a5c; color: #fff; border: none;
           border-radius: 4px; cursor: pointer; font: inherit; }
  .error { color: #c0392b; margin-bottom: 14px; font-size: .9rem; }
</style>
</head>
<body>
<h1>Forum Setup</h1>
<p style="margin-bottom:20px;color:#555">Create your admin account to get started.</p>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif ?>
<form method="post">
  <label>Username</label>
  <input type="text" name="username" required minlength="3" maxlength="50" autofocus>
  <label>Email</label>
  <input type="email" name="email" required>
  <label>Password</label>
  <input type="password" name="password" required minlength="8">
  <button type="submit">Install &amp; Create Admin</button>
</form>
</body>
</html>
