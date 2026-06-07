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
        $_SESSION['role']     = $user['role'] ?? 'user';
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
            'role'     => $_SESSION['role'],
            'is_admin' => $_SESSION['role'] === 'admin',
            'is_mod'   => in_array($_SESSION['role'], ['admin', 'moderator']),
        ];
    }

    public static function isAdmin(): bool {
        return ($_SESSION['role'] ?? '') === 'admin';
    }

    public static function isMod(): bool {
        return in_array($_SESSION['role'] ?? '', ['admin', 'moderator']);
    }

    public static function require(): void {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    public static function requireAdmin(): void {
        self::require();
        if (!self::isAdmin()) {
            http_response_code(403);
            exit('Forbidden');
        }
        header('Cache-Control: no-store');
    }

    public static function requireMod(): void {
        self::require();
        if (!self::isMod()) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}
