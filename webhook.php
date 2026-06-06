<?php
define('ROOT', __DIR__);
require ROOT . '/src/Updater.php';

$cfg    = file_exists(ROOT . '/config.php') ? require ROOT . '/config.php' : [];
$secret = $cfg['webhook_secret'] ?? '';
$event  = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

// Called by GitHub webhook
if ($event !== '') {
    $payload = file_get_contents('php://input');
    $sig     = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

    if ($secret && !hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $sig)) {
        http_response_code(403);
        exit('Forbidden');
    }

    if ($event !== 'push') {
        http_response_code(200);
        exit('OK (ignored)');
    }

    $branch = $cfg['deploy_branch'] ?? 'main';
    $data   = json_decode($payload, true) ?? [];
    if (($data['ref'] ?? '') !== "refs/heads/{$branch}") {
        http_response_code(200);
        exit('OK (wrong branch)');
    }
}

// Manual trigger or GitHub push — run the update
header('Cache-Control: no-store');
Updater::log('Update triggered (' . ($event === 'push' ? 'webhook' : 'manual') . ')');
$ok = Updater::applyZip(Updater::fetch(Updater::ZIP_URL));
if ($ok) file_put_contents(ROOT . '/.update_check', time());
Updater::log($ok ? 'Update applied' : 'Update failed');

header('Content-Type: text/plain');
http_response_code($ok ? 200 : 500);
echo $ok ? 'Updated.' : 'Failed. Check update.log.';
