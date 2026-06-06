<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
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
    <link rel="stylesheet" href="/assets/style.css">
    <?php if (!empty($schema)): ?>
    <script type="application/ld+json"><?= $schema ?></script>
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
</body>
</html>
