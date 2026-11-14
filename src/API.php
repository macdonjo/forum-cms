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

        // Cloudflare's "Cache All Pages" rule edge-caches GET responses regardless
        // of Authorization or Cache-Control, and would then serve this JSON to
        // anyone. Only a request carrying a forum_sess cookie hits the bypass
        // rule, so refuse to return data on any GET that lacks one.
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_COOKIE['forum_sess'])) {
            self::respond(['error' => 'GET API requests must send a forum_sess cookie (any value) so Cloudflare bypasses its edge cache, e.g. -H "Cookie: forum_sess=api".'], 400);
        }
    }

    /**
     * Parse an optional client-supplied timestamp (ISO 8601 or anything strtotime
     * accepts) into a MySQL DATETIME in the server's timezone. Returns null when
     * absent; responds 422 when unparseable or in the future.
     */
    public static function timestamp(mixed $value, string $field = 'created_at'): ?string {
        if ($value === null || $value === '') return null;
        $ts = is_numeric($value) ? (int)$value : strtotime((string)$value);
        if ($ts === false)       self::respond(['error' => "$field is not a valid date/time."], 422);
        if ($ts > time() + 60)   self::respond(['error' => "$field cannot be in the future."], 422);
        return date('Y-m-d H:i:s', $ts);
    }

    /** Clamp ?limit= / ?offset= query params. */
    public static function paging(int $default = 50, int $max = 200): array {
        $limit  = max(1, min($max, (int)($_GET['limit'] ?? $default)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        return [$limit, $offset];
    }

    public static function respond(array $data, int $code = 200): never {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store, private');
        echo json_encode($data);
        exit;
    }

    public static function generateKey(): string {
        return bin2hex(random_bytes(32));
    }
}
