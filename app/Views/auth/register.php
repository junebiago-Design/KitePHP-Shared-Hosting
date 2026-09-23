<?php add_js('js/auth.js'); ?>
<h1 class="h3 mb-1">Create account</h1>
<p class="text-body-secondary mb-4">It only takes a minute.</p>

<form method="post" action="<?= url('/register') ?>">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="name" class="form-label">Name</label>
        <input type="text" class="form-control<?= invalid('name') ?>" id="name" name="name"
               value="<?= e(old('name')) ?>" autocomplete="name" required autofocus>
        <?php if ($err = error('name')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
    </div>

    <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <input type="email" class="form-control<?= invalid('email') ?>" id="email" name="email"
               value="<?= e(old('email')) ?>" autocomplete="email" required>
        <?php if ($err = error('email')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Password <span class="text-body-secondary small">(min 8 characters)</span></label>
        <div class="input-group has-validation">
            <input type="password" class="form-control<?= invalid('password') ?>" id="password" name="password"
                   autocomplete="new-password" required>
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="password">Show</button>
            <?php if ($err = error('password')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="mb-4">
        <label for="password_confirmation" class="form-label">Confirm password</label>
        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
               autocomplete="new-password" required>
    </div>

    <button class="btn btn-primary w-100" type="submit">Create account</button>
</form>

<p class="text-center text-body-secondary mt-4 mb-0">Already registered? <a href="<?= url('/login') ?>">Log in</a></p>
