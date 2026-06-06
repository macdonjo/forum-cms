<?php
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header("Location: {$url}");
    exit;
}

function slug(string $text): string {
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-') ?: 'post';
}

function unique_slug(string $table, string $base, string $extra_col = '', int $extra_val = 0): string {
    $s     = slug($base);
    $where = $extra_col ? "AND {$extra_col} = ?" : '';

    $exists = DB::one("SELECT id FROM {$table} WHERE slug = ? {$where}",
        $extra_col ? [$s, $extra_val] : [$s]);
    if (!$exists) return $s;

    for ($i = 2; ; $i++) {
        $c = "{$s}-{$i}";
        if (!DB::one("SELECT id FROM {$table} WHERE slug = ? {$where}",
            $extra_col ? [$c, $extra_val] : [$c])) return $c;
    }
}

function excerpt(string $text, int $len = 155): string {
    $text = strip_tags($text);
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len) . '…' : $text;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = compact('type', 'msg');
}

function get_flash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function paginate(int $total, int $per_page, int $current): array {
    $last    = max(1, (int)ceil($total / $per_page));
    $current = max(1, min($current, $last));
    return [
        'total'    => $total,
        'per_page' => $per_page,
        'current'  => $current,
        'last'     => $last,
        'offset'   => ($current - 1) * $per_page,
        'has_prev' => $current > 1,
        'has_next' => $current < $last,
    ];
}

function upload_image(array $file): ?string {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return null;

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mime    = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) return null;
    if ($file['size'] > 8 * 1024 * 1024) return null;

    $ext = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    };
    $dir  = ROOT . '/uploads/' . date('Y/m');
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], "{$dir}/{$name}")) return null;

    return date('Y/m') . '/' . $name;
}

function render(string $tpl, array $vars = []): void {
    global $config;
    $vars += ['config' => $config, 'flash' => get_flash()];
    extract($vars, EXTR_SKIP);
    ob_start();
    require ROOT . "/templates/{$tpl}.php";
    $content = ob_get_clean();
    require ROOT . '/templates/layout.php';
}
