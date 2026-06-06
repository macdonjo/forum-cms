<nav class="breadcrumb">
    <a href="/">Home</a> &rsaquo; <?= h($section['name']) ?>
</nav>
<div class="page-header">
    <h1><?= h($section['name']) ?></h1>
    <?php if (Auth::check()): ?>
    <a href="/new-thread?section=<?= h($section['slug']) ?>" class="btn">+ New Thread</a>
    <?php endif ?>
</div>
<?php if ($section['description']): ?>
<p class="section-description"><?= h($section['description']) ?></p>
<?php endif ?>

<?php if (empty($threads)): ?>
<p class="empty-state">
    No threads yet.
    <?php if (Auth::check()): ?>
    <a href="/new-thread?section=<?= h($section['slug']) ?>">Start one.</a>
    <?php else: ?>
    <a href="/login">Login</a> to start a thread.
    <?php endif ?>
</p>
<?php else: ?>
<table class="board-table">
    <thead>
        <tr>
            <th>Thread</th>
            <th>Replies</th>
            <th>Updated</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($threads as $t): ?>
        <tr>
            <td class="thread-cell">
                <a href="/s/<?= h($section['slug']) ?>/<?= h($t['slug']) ?>"><?= h($t['title']) ?></a>
                <span class="thread-author">by <?= h($t['username']) ?></span>
            </td>
            <td class="stat-cell"><?= (int)$t['reply_count'] ?></td>
            <td class="stat-cell"><?= date('M j, Y', strtotime($t['updated_at'])) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>

<?php if ($pg['last'] > 1): ?>
<nav class="pagination" aria-label="Thread pages">
    <?php if ($pg['has_prev']): ?>
    <a href="?page=<?= $pg['current'] - 1 ?>" rel="prev">&larr; Prev</a>
    <?php endif ?>
    <span>Page <?= $pg['current'] ?> of <?= $pg['last'] ?></span>
    <?php if ($pg['has_next']): ?>
    <a href="?page=<?= $pg['current'] + 1 ?>" rel="next">Next &rarr;</a>
    <?php endif ?>
</nav>
<?php endif ?>
<?php endif ?>
