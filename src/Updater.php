<?php
class Updater {
    const ZIP_URL   = 'https://github.com/macdonjo/forum-cms/archive/refs/heads/main.zip';
    const VER_URL   = 'https://raw.githubusercontent.com/macdonjo/forum-cms/main/version.txt';
    const INTERVAL  = 86400; // seconds between checks (24h)

    // These paths are never touched during an update
    const PROTECTED = ['config.php', 'uploads/'];

    public static function currentVersion(): string {
        $f = ROOT . '/version.txt';
        return file_exists($f) ? trim(file_get_contents($f)) : 'unknown';
    }

    public static function lastChecked(): ?int {
        $f = ROOT . '/.update_check';
        return file_exists($f) ? (int)file_get_contents($f) : null;
    }

    public static function shouldCheck(): bool {
        $last = self::lastChecked();
        return $last === null || (time() - $last) >= self::INTERVAL;
    }

    // Called in a shutdown function — response already sent to user
    public static function checkAndUpdate(): void {
        file_put_contents(ROOT . '/.update_check', time());

        $remote = self::fetch(self::VER_URL);
        if (!$remote) return;
        $remote = trim($remote);
        if ($remote === self::currentVersion()) return;

        self::log("Update available: {$remote} (installed: " . self::currentVersion() . ')');
        $ok = self::applyZip(self::fetch(self::ZIP_URL));
        self::log($ok ? "Updated to {$remote}" : 'Update failed');
    }

    // Can also be called directly (e.g. from admin "Update now" action)
    public static function applyZip(string|false $data): bool {
        if (!$data) return false;

        $tmp = sys_get_temp_dir() . '/forum_update_' . uniqid() . '.zip';
        if (file_put_contents($tmp, $data) === false) return false;

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) { unlink($tmp); return false; }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            // GitHub zips wrap everything in a top-level "repo-branch/" folder — strip it
            $rel = (string)preg_replace('#^[^/]+/#', '', $entry);
            if ($rel === '') continue;
            if (self::isProtected($rel)) continue;

            $dest = ROOT . '/' . $rel;
            if (str_ends_with($entry, '/')) {
                if (!is_dir($dest)) mkdir($dest, 0755, true);
            } else {
                $dir = dirname($dest);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                file_put_contents($dest, $zip->getFromIndex($i));
            }
        }

        $zip->close();
        unlink($tmp);
        return true;
    }

    public static function fetch(string $url): string|false {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_USERAGENT      => 'forum-cms-updater/1.0',
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($body !== false && $code === 200) ? $body : false;
        }
        // Fallback for hosts with curl disabled
        return @file_get_contents($url);
    }

    private static function isProtected(string $rel): bool {
        foreach (self::PROTECTED as $p) {
            if ($rel === $p || str_starts_with($rel, rtrim($p, '/') . '/')) return true;
        }
        return false;
    }

    public static function log(string $msg): void {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        file_put_contents(ROOT . '/update.log', $line, FILE_APPEND | LOCK_EX);
    }
}
