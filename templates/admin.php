<h1>Admin</h1>

<section class="admin-section" style="margin-bottom:20px">
    <h2>Settings</h2>
    <form method="post" action="/cp/settings">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Forum name</label>
            <input type="text" name="forum_name" value="<?= h($config['app_name']) ?>" required maxlength="100">
        </div>
        <div class="form-group">
            <label>Forum description <small>(homepage meta description)</small></label>
            <input type="text" name="forum_description" value="<?= h($config['forum_description']) ?>" maxlength="255">
        </div>
        <div class="form-group">
            <label>Threads per page <input type="number" name="posts_per_page" value="<?= (int)$config['posts_per_page'] ?>" min="5" max="100" style="width:80px"></label>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" name="allow_registration" <?= $config['allow_registration'] ? 'checked' : '' ?>>
                Allow new registrations
            </label>
        </div>
        <button type="submit" class="btn">Save Settings</button>
    </form>
</section>

<section class="admin-section" style="margin-bottom:20px">
    <h2>API</h2>
    <p style="margin-bottom:12px;font-size:.9rem;color:#555">Use this key to authenticate API requests via <code>Authorization: Bearer {key}</code> or <code>?key={key}</code>.</p>
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
        <input type="password" value="<?= h($api_key) ?>" readonly
               style="flex:1;min-width:200px;font-family:monospace;font-size:.85rem;padding:7px 10px;border:1px solid #ccc;border-radius:4px;background:#f9f9f9"
               onclick="this.type='text'" title="Click to reveal">
        <button onclick="navigator.clipboard.writeText('<?= h($api_key) ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)"
                class="btn" type="button">Copy</button>
    </div>
    <form method="post" action="/cp/api/regenerate" onsubmit="return confirm('Regenerate API key? The old key will stop working immediately.')">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger">Regenerate Key</button>
    </form>
    <h3 style="margin-top:20px">Endpoints</h3>
    <table class="board-table" style="margin-top:8px">
        <thead><tr><th>Method</th><th>Endpoint</th><th>Description</th></tr></thead>
        <tbody>
            <tr>
                <td><code>POST</code></td>
                <td><code><?= h($config['app_url']) ?>/api/users</code></td>
                <td style="font-size:.85rem">Create a user. Body: <code>username</code>, <code>email</code>, <code>password</code>, <code>role</code> (optional)</td>
            </tr>
            <tr>
                <td><code>POST</code></td>
                <td><code><?= h($config['app_url']) ?>/api/threads</code></td>
                <td style="font-size:.85rem">Create a thread. Body: <code>title</code>, <code>body</code>, <code>section</code> (slug) or <code>section_id</code>, <code>user_id</code> (optional)</td>
            </tr>
            <tr>
                <td><code>POST</code></td>
                <td><code><?= h($config['app_url']) ?>/api/update</code></td>
                <td style="font-size:.85rem">Force-pull latest update from GitHub immediately</td>
            </tr>
        </tbody>
    </table>
</section>

<section class="admin-section" style="margin-bottom:20px">
    <h2>Updates</h2>
    <p style="margin-bottom:12px;font-size:.9rem;color:#555">
        Version: <strong><?= h(Updater::currentVersion()) ?></strong>
        &nbsp;&middot;&nbsp;
        <?php $checked = Updater::lastChecked(); ?>
        Last checked: <strong><?= $checked ? date('M j, Y g:i a', $checked) : 'never' ?></strong>
        &nbsp;&middot;&nbsp;
        Updates apply automatically within 24 hours of a release.
    </p>
    <form method="post" action="/cp/update">
        <?= csrf_field() ?>
        <button type="submit" class="btn">Update Now</button>
    </form>
    <?php if (!empty($update_msg)): ?>
    <p style="margin-top:10px;font-size:.9rem;color:<?= $update_msg === 'Updated successfully.' ? '#155724' : '#721c24' ?>">
        <?= h($update_msg) ?>
    </p>
    <?php endif ?>
</section>

<section class="admin-section" style="margin-bottom:20px">
    <h2>Users</h2>
    <table class="board-table">
        <thead>
            <tr><th>Username</th><th>Email</th><th>Role</th><th>Joined</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><strong><?= h($u['username']) ?></strong><?= role_badge($u['role']) ?></td>
            <td style="font-size:.85rem;color:#666"><?= h($u['email']) ?></td>
            <td><?= h($u['role']) ?></td>
            <td style="font-size:.85rem;color:#666"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
            <td>
                <?php if ($u['role'] !== 'admin'): ?>
                <form method="post" action="/cp/user/role">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <select name="role" onchange="this.form.submit()" style="font:inherit;padding:4px;border:1px solid #ccc;border-radius:4px">
                        <option value="user"      <?= $u['role'] === 'user'      ? 'selected' : '' ?>>User</option>
                        <option value="moderator" <?= $u['role'] === 'moderator' ? 'selected' : '' ?>>Moderator</option>
                    </select>
                </form>
                <?php endif ?>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</section>

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
            <td><a href="/<?= h($s['slug']) ?>"><?= h($s['name']) ?></a></td>
            <td><code><?= h($s['slug']) ?></code></td>
            <td><?= (int)$s['display_order'] ?></td>
            <td>
                <form method="post" action="/cp/section/delete"
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
    <form method="post" action="/cp/section" class="post-form">
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
