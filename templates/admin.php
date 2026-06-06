<h1>Admin</h1>

<section class="admin-section">
    <h2>Sections</h2>

    <?php if (empty($sections)): ?>
    <p class="empty-state">No sections yet.</p>
    <?php else: ?>
    <table class="board-table">
        <thead>
            <tr><th>Name</th><th>Slug</th><th>Order</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($sections as $s): ?>
        <tr>
            <td><a href="/s/<?= h($s['slug']) ?>"><?= h($s['name']) ?></a></td>
            <td><code><?= h($s['slug']) ?></code></td>
            <td><?= (int)$s['display_order'] ?></td>
            <td>
                <form method="post" action="/admin/section/delete"
                      onsubmit="return confirm('Delete “<?= h($s['name']) ?>” and all its threads?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php endif ?>

    <h3>Add Section</h3>
    <form method="post" action="/admin/section" class="post-form">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Name <input type="text" name="name" required maxlength="100"></label>
        </div>
        <div class="form-group">
            <label>Description <input type="text" name="description" maxlength="255"></label>
        </div>
        <div class="form-group">
            <label>Display order <input type="number" name="display_order" value="0" style="width:80px"></label>
        </div>
        <button type="submit" class="btn">Add Section</button>
    </form>
</section>
