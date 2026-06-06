<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <?php if (!empty($noindex)): ?>
    <meta name="robots" content="noindex,follow">
    <?php endif ?>
    <?php if (!empty($description)): ?>
    <meta name="description" content="<?= h($description) ?>">
    <?php endif ?>
    <?php if (!empty($canonical)): ?>
    <link rel="canonical" href="<?= h($canonical) ?>">
    <?php endif ?>
    <?php if (!empty($prev_url)): ?>
    <link rel="prev" href="<?= h($prev_url) ?>">
    <?php endif ?>
    <?php if (!empty($next_url)): ?>
    <link rel="next" href="<?= h($next_url) ?>">
    <?php endif ?>
    <meta property="og:site_name" content="<?= h($config['app_name']) ?>">
    <meta property="og:title" content="<?= h($title) ?>">
    <meta property="og:type" content="<?= !empty($og_type) ? h($og_type) : 'website' ?>">
    <?php if (!empty($description)): ?>
    <meta property="og:description" content="<?= h($description) ?>">
    <?php endif ?>
    <?php if (!empty($canonical)): ?>
    <meta property="og:url" content="<?= h($canonical) ?>">
    <?php endif ?>
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= h($title) ?>">
    <?php if (!empty($description)): ?>
    <meta name="twitter:description" content="<?= h($description) ?>">
    <?php endif ?>
    <meta name="csrf" content="<?= h(csrf_token()) ?>">
    <link rel="stylesheet" href="/assets/style.css">
    <?php if (!empty($schema)): ?>
    <script type="application/ld+json"><?= $schema ?></script>
    <?php endif ?>
    <?php if (!empty($breadcrumb_schema)): ?>
    <script type="application/ld+json"><?= $breadcrumb_schema ?></script>
    <?php endif ?>
</head>
<body>
<header id="site-header">
    <div class="container">
        <a href="/" class="site-name"><?= h($config['app_name']) ?></a>
        <nav>
            <?php if (Auth::check()): $u = Auth::user(); ?>
                <a href="/new-thread">+ New Thread</a>
                <span class="username"><?= h($u['username']) ?></span>
                <?php if ($u['is_admin']): ?><a href="/cp">Admin CP</a><?php endif ?>
                <a href="/logout">Logout</a>
            <?php else: ?>
                <a href="/login">Login</a>
                <a href="/register">Register</a>
            <?php endif ?>
        </nav>
    </div>
</header>
<main class="container">
    <?php if (!empty($flash)): ?>
    <div class="flash flash--<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif ?>
    <?= $content ?>
</main>
<footer id="site-footer">
    <div class="container">
        <p>&copy; <?= date('Y') ?> <?= h($config['app_name']) ?> &middot; v<?= h(Updater::currentVersion()) ?></p>
    </div>
</footer>
<script>
function bbWrap(btn, open, close) {
    var ta = btn.closest('.bb-editor').querySelector('textarea');
    var s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.slice(0,s) + open + ta.value.slice(s,e) + close + ta.value.slice(e);
    ta.selectionStart = s + open.length;
    ta.selectionEnd   = s + open.length + (e - s);
    ta.focus();
}
function bbImage(btn) {
    var input = btn.parentElement.querySelector('.bb-img-input');
    input.onchange = function() {
        if (!input.files[0]) return;
        var fd = new FormData();
        fd.append('image', input.files[0]);
        fd.append('csrf', document.querySelector('meta[name="csrf"]').content);
        fetch('/upload', {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(d) {
                if (!d.url) return;
                var ta = btn.closest('.bb-editor').querySelector('textarea');
                var pos = ta.selectionStart;
                var tag = '[img]' + d.url + '[/img]';
                ta.value = ta.value.slice(0,pos) + tag + ta.value.slice(pos);
                ta.selectionStart = ta.selectionEnd = pos + tag.length;
                ta.focus();
            });
        input.value = '';
    };
    input.click();
}
</script>
</body>
</html>
