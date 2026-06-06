<nav class="breadcrumb">
    <a href="/">Home</a> &rsaquo;
    <a href="/<?= h($section['slug']) ?>"><?= h($section['name']) ?></a> &rsaquo;
    <?= h($thread['title']) ?>
</nav>

<article class="post thread-op">
    <header class="post-header">
        <h1><?= h($thread['title']) ?></h1>
        <div class="post-meta">
            by <strong><?= h($thread['author']) ?></strong><?= role_badge($thread['author_role']) ?>
            &middot; <?= date('M j, Y \a\t g:i a', strtotime($thread['created_at'])) ?>
            &middot; <?= (int)$thread['view_count'] ?> views
        </div>
    </header>
    <div class="post-body"><?= bbcode($thread['body']) ?></div>
    <?php if ($thread['image']): ?>
    <div class="post-image">
        <img src="/uploads/<?= h($thread['image']) ?>" alt="" loading="lazy">
    </div>
    <?php endif ?>
    <?php if (Auth::isMod()): ?>
    <div class="mod-actions">
        <form method="post" action="/thread/delete" onsubmit="return confirm('Delete this thread and all replies?')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$thread['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete Thread</button>
        </form>
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
            <strong><?= h($r['author']) ?></strong><?= role_badge($r['author_role']) ?>
            &middot; <?= date('M j, Y \a\t g:i a', strtotime($r['created_at'])) ?>
            <?php if (Auth::isMod()): ?>
            <form method="post" action="/reply/delete" class="inline-delete" onsubmit="return confirm('Delete this reply?')">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
            <?php endif ?>
        </div>
        <div class="post-body"><?= bbcode($r['body']) ?></div>
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
        <div class="bb-editor">
            <div class="bb-toolbar">
                <button type="button" class="bb-btn" onclick="bbWrap(this,'[b]','[/b]')"><b>B</b></button>
                <button type="button" class="bb-btn" onclick="bbWrap(this,'[i]','[/i]')"><i>I</i></button>
                <button type="button" class="bb-btn" onclick="bbImage(this)">+ Image</button>
                <input type="file" class="bb-img-input" accept="image/*" style="display:none">
            </div>
            <textarea name="body" rows="6" placeholder="Write your reply…" required></textarea>
        </div>
        <div class="form-row" style="margin-top:10px">
            <button type="submit" class="btn">Post Reply</button>
        </div>
    </form>
</section>
<?php else: ?>
<p class="login-prompt"><a href="/login">Login</a> or <a href="/register">register</a> to reply.</p>
<?php endif ?>
