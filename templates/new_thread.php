<h1>New Thread</h1>

<?php if (!empty($errors)): ?>
<ul class="errors">
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach ?>
</ul>
<?php endif ?>

<form method="post" action="/new-thread" enctype="multipart/form-data" class="post-form">
    <?= csrf_field() ?>
    <div class="form-group">
        <label for="section_id">Section</label>
        <select id="section_id" name="section_id" required>
            <option value="">— Select a section —</option>
            <?php foreach ($sections as $s): ?>
            <option value="<?= (int)$s['id'] ?>"
                <?= (($old['section_id'] ?? '') == $s['id'] || $preselect === $s['slug']) ? 'selected' : '' ?>>
                <?= h($s['name']) ?>
            </option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label for="title">Title</label>
        <input id="title" type="text" name="title" value="<?= h($old['title'] ?? '') ?>"
               required maxlength="255" autofocus>
    </div>
    <div class="form-group">
        <label for="body">Body</label>
        <textarea id="body" name="body" rows="12" required><?= h($old['body'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
        <label for="image">Image (optional, max 8 MB)</label>
        <input id="image" type="file" name="image" accept="image/*">
    </div>
    <button type="submit" class="btn">Post Thread</button>
</form>
