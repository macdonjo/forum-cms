<nav class="breadcrumb">
    <a href="/">Home</a> &rsaquo;
    <a href="/s/<?= h($section['slug']) ?>"><?= h($section['name']) ?></a> &rsaquo;
    <?= h($thread['title']) ?>
</nav>

<article class="post thread-op">
    <header class="post-header">
        <h1><?= h($thread['title']) ?></h1>
        <div class="post-meta">
            by <strong><?= h($thread['author']) ?></strong>
            &middot; <?= date('M j, Y \a\t g:i a', strtotime($thread['created_at'])) ?>
            &middot; <?= (int)$thread['view_count'] ?> views
        </div>
    </header>
    <div class="post-body"><?= nl2br(h($thread['body'])) ?></div>
    <?php if ($thread['image']): ?>
    <div class="post-image">
        <img src="/uploads/<?= h($thread['image']) ?>" alt="" loading="lazy">
    </div>
    <?php endif ?>
</article>

<?php if ($pg['last'] > 1): ?>
<nav class="pagination" aria-label="Reply pages">
    <?php if ($pg['has_prev']): ?>
    <a href="?page=<?= $pg['current'] - 1 ?>" rel="prev">&larr; Prev</a>
    <?php endif ?>
    <span>Page <?= $pg['current'] ?> of <?= $pg['last'] ?></span>
    <?php if ($pg['has_next']): ?>
    <a href="?page=<?= $pg['current'] + 1 ?>" rel="next">Next &rarr;</a>
    <?php endif ?>
</nav>
<?php endif ?>

<?php if (!empty($replies)): ?>
<section class="replies">
    <h2 class="replies-heading"><?= (int)$thread['reply_count'] ?> <?= $thread['reply_count'] === 1 ? 'Reply' : 'Replies' ?></h2>
    <?php foreach ($replies as $r): ?>
    <article class="post reply">
        <div class="post-meta">
            <strong><?= h($r['author']) ?></strong>
            &middot; <?= date('M j, Y \a\t g:i a', strtotime($r['created_at'])) ?>
        </div>
        <div class="post-body"><?= nl2br(h($r['body'])) ?></div>
        <?php if ($r['image']): ?>
        <div class="post-image">
            <img src="/uploads/<?= h($r['image']) ?>" alt="" loading="lazy">
        </div>
        <?php endif ?>
    </article>
    <?php endforeach ?>
</section>
<?php endif ?>

<div id="bottom"></div>

<?php if (Auth::check()): ?>
<section class="reply-form">
    <h2>Post a Reply</h2>
    <form method="post" action="/reply" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
        <textarea name="body" rows="6" placeholder="Write your reply…" required></textarea>
        <div class="form-row">
            <label>Image (optional) <input type="file" name="image" accept="image/*"></label>
            <button type="submit" class="btn">Post Reply</button>
        </div>
    </form>
</section>
<?php else: ?>
<p class="login-prompt"><a href="/login">Login</a> or <a href="/register">register</a> to reply.</p>
<?php endif ?>
