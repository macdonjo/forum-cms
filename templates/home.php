<h1 class="sr-only"><?= h($config['app_name']) ?></h1>

<?php if (empty($sections)): ?>
<p class="empty-state">
    No sections yet.
    <?php if (Auth::check() && Auth::user()['is_admin']): ?>
    <a href="/cp">Create one in the admin panel.</a>
    <?php endif ?>
</p>
<?php else: ?>
<table class="board-table">
    <thead>
        <tr>
            <th>Section</th>
            <th>Threads</th>
            <th>Last activity</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($sections as $s): ?>
        <tr>
            <td class="section-cell">
                <a href="/<?= h($s['slug']) ?>" class="section-link"><?= h($s['name']) ?></a>
                <?php if ($s['description']): ?>
                <p class="section-desc"><?= h($s['description']) ?></p>
                <?php endif ?>
            </td>
            <td class="stat-cell"><?= (int)$s['thread_count'] ?></td>
            <td class="stat-cell">
                <?php if ($s['last_activity']): ?>
                <time datetime="<?= date('c', strtotime($s['last_activity'])) ?>" data-fmt="date"><?= date('M j, Y', strtotime($s['last_activity'])) ?></time>
                <?php else: ?>—<?php endif ?>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
<?php endif ?>
