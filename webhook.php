<?php
$cfg    = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$secret = $cfg['webhook_secret'] ?? '';
$branch = $cfg['deploy_branch'] ?? 'main';

$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if ($secret && !hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $sig)) {
    http_response_code(403);
    exit('Forbidden');
}

if (($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '') !== 'push') {
    http_response_code(200);
    exit('OK');
}

$data = json_decode($payload, true) ?? [];
if (($data['ref'] ?? '') !== "refs/heads/{$branch}") {
    http_response_code(200);
    exit('OK (wrong branch)');
}

$dir    = escapeshellarg(__DIR__);
$br     = escapeshellarg($branch);
$output = shell_exec("git -C {$dir} pull origin {$br} 2>&1");

header('Content-Type: text/plain');
echo $output ?: 'done';
