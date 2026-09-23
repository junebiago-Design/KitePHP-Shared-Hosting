<?php
add_js('js/auth.js');
$editing = $user !== null;
$action = $editing ? url('/admin/users/' . $user->id) : url('/admin/users');
$currentRole = old('role', $editing ? $user->role : config('auth.default_role', 'user'));
?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <h1 class="h3 mb-4"><?= $editing ? 'Edit user' : 'Add user' ?></h1>

        <form method="post" action="<?= $action ?>">
            <?= csrf_field() ?>
            <?php if ($editing): ?><?= method_field('PUT') ?><?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Name</label>
                    <input type="text" class="form-control<?= invalid('name') ?>" id="name" name="name"
                           value="<?= e(old('name', $editing ? $user->name : '')) ?>" required>
                    <?php if ($err = error('name')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control<?= invalid('email') ?>" id="email" name="email"
                           value="<?= e(old('email', $editing ? $user->email : '')) ?>" required>
                    <?php if ($err = error('email')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                </div>
            </div>

            <div class="mb-3">
                <label for="role" class="form-label">Role</label>
                <select class="form-select<?= invalid('role') ?>" id="role" name="role" <?= $isSelf ? 'disabled' : '' ?>>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= e($r) ?>" <?= $currentRole === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isSelf): ?><div class="form-text">You can't change your own role.</div><?php endif; ?>
                <?php if ($err = error('role')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label"><?= $editing ? 'New password' : 'Password' ?></label>
                <div class="input-group has-validation">
                    <input type="password" class="form-control<?= invalid('password') ?>" id="password" name="password"
                           autocomplete="new-password" <?= $editing ? '' : 'required' ?>>
                    <button class="btn btn-outline-secondary" type="button" data-toggle-password="password">Show</button>
                    <?php if ($err = error('password')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                </div>
                <div class="form-text"><?= $editing ? 'Leave blank to keep the current password.' : 'At least 8 characters.' ?></div>
            </div>

            <div class="mb-4">
                <label for="password_confirmation" class="form-label">Confirm password</label>
                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                       autocomplete="new-password" <?= $editing ? '' : 'required' ?>>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Add user' ?></button>
                <a class="btn btn-outline-secondary" href="<?= url('/admin/users') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
