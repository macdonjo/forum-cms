<?php
class Auth {
    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('forum_sess');
            session_start();
        }
    }

    public static function login(array $user): void {
        session_regenerate_id(true);
        $_SESSION['uid']      = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_admin'] = (bool)$user['is_admin'];
    }

    public static function logout(): void {
        $_SESSION = [];
        session_destroy();
    }

    public static function check(): bool {
        return isset($_SESSION['uid']);
    }

    public static function user(): ?array {
        if (!self::check()) return null;
        return [
            'id'       => $_SESSION['uid'],
            'username' => $_SESSION['username'],
            'is_admin' => $_SESSION['is_admin'],
        ];
    }

    public static function require(): void {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    public static function requireAdmin(): void {
        self::require();
        if (!$_SESSION['is_admin']) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}
