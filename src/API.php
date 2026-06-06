<?php
class API {
    public static function auth(): void {
        $stored = DB::one("SELECT value FROM settings WHERE `key` = 'api_key'")['value'] ?? '';

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $provided = str_starts_with($header, 'Bearer ')
            ? substr($header, 7)
            : ($_GET['key'] ?? '');

        if (!$stored || !$provided || !hash_equals($stored, trim($provided))) {
            self::respond(['error' => 'Unauthorized'], 401);
        }
    }

    public static function respond(array $data, int $code = 200): never {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public static function generateKey(): string {
        return bin2hex(random_bytes(32));
    }
}
