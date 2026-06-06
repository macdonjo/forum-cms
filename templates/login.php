<h1>Login</h1>

<?php if (!empty($error)): ?>
<p class="error"><?= h($error) ?></p>
<?php endif ?>

<form method="post" action="/login" class="auth-form">
    <?= csrf_field() ?>
    <div class="form-group">
        <label for="login">Username or email</label>
        <input id="login" type="text" name="login" required autofocus autocomplete="username">
    </div>
    <div class="form-group">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn">Login</button>
    <p>No account? <a href="/register">Register</a></p>
</form>
