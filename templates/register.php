<h1>Register</h1>

<?php if (!empty($errors)): ?>
<ul class="errors">
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach ?>
</ul>
<?php endif ?>

<form method="post" action="/register" class="auth-form">
    <?= csrf_field() ?>
    <div class="form-group">
        <label for="username">Username</label>
        <input id="username" type="text" name="username"
               value="<?= h($old['username'] ?? '') ?>"
               required minlength="3" maxlength="50" autofocus autocomplete="username">
    </div>
    <div class="form-group">
        <label for="email">Email</label>
        <input id="email" type="email" name="email"
               value="<?= h($old['email'] ?? '') ?>"
               required autocomplete="email">
    </div>
    <div class="form-group">
        <label for="password">Password <small>(min 8 characters)</small></label>
        <input id="password" type="password" name="password"
               required minlength="8" autocomplete="new-password">
    </div>
    <button type="submit" class="btn">Register</button>
    <p>Already have an account? <a href="/login">Login</a></p>
</form>
