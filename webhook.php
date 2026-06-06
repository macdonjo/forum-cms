<?php
// Optional: point GitHub's webhook here for instant deploys instead of waiting 24h.
// Set the same secret in your repo Settings → Webhooks and in config.php.

define('ROOT', __DIR__);
require ROOT . '/src/Updater.php';

$cfg    = file_exists(ROOT . '/config.php') ? require ROOT . '/config.php' : [];
$secret = $cfg['webhook_secret'] ?? '';

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

$branch = $cfg['deploy_branch'] ?? 'main';
$data   = json_decode($payload, true) ?? [];
if (($data['ref'] ?? '') !== "refs/heads/{$branch}") {
    http_response_code(200);
    exit('OK (wrong branch)');
}

Updater::log('Webhook triggered update');
$ok = Updater::applyZip(Updater::fetch(Updater::ZIP_URL));
if ($ok) file_put_contents(ROOT . '/.update_check', time());
Updater::log($ok ? 'Webhook update applied' : 'Webhook update failed');

header('Content-Type: text/plain');
echo $ok ? 'Updated.' : 'Failed. Check update.log.';
