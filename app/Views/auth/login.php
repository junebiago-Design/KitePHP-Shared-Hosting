<?php add_js('js/auth.js'); ?>
<h1 class="h3 mb-1 text-center">Welcome Back</h1>
<p class="text-body-secondary mb-4 text-center">Log in to continue.</p>

<form method="post" action="<?= url('/login') ?>">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <input type="email" class="form-control<?= invalid('email') ?>" id="email" name="email"
               value="<?= e(old('email')) ?>" autocomplete="username" required autofocus>
        <?php if ($err = error('email')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
    </div>

    <div class="mb-4">
        <label for="password" class="form-label">Password</label>
        <div class="input-group has-validation">
            <input type="password" class="form-control<?= invalid('password') ?>" id="password" name="password"
                   autocomplete="current-password" required>
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="password">Show</button>
            <?php if ($err = error('password')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
        </div>
    </div>

    <button class="btn btn-primary w-100" type="submit">Log in</button>
</form>

<?php if (registration_open()): ?>
    <p class="text-center text-body-secondary mt-4 mb-0">No account? <a href="<?= url('/register') ?>">Create one</a></p>
<?php endif; ?>

<?php if (config('app.debug')): ?>
    <hr>
    <p class="small text-body-secondary text-center mb-0">
        Demo accounts (debug only), password <code>password</code>:<br>
        admin@example.com · editor@example.com · user@example.com
    </p>
    <?php else: ?>
    <hr>
    <p class="small text-body-secondary text-center mb-0">
       <a class="btn btn-primary" href="<?= url('/') ?>">Back home</a><br><br>
        Built with <?= e(config('app.name')) ?> · PHP <?= e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?>
    </p>
<?php endif; ?>
