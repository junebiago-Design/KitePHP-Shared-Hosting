<div class="row justify-content-center">
    <div class="col-lg-6">
        <h1 class="h3 mb-4">Add role</h1>

        <form method="post" action="<?= url('/admin/roles') ?>">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="name" class="form-label">Key</label>
                <input type="text" class="form-control<?= invalid('name') ?>" id="name" name="name"
                       value="<?= e(old('name')) ?>" required autofocus>
                <div class="form-text">Lowercase, e.g. <code>moderator</code>. Can't be changed later.</div>
                <?php if ($err = error('name')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="label" class="form-label">Display name</label>
                <input type="text" class="form-control<?= invalid('label') ?>" id="label" name="label"
                       value="<?= e(old('label')) ?>" required>
                <?php if ($err = error('label')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
            </div>

            <p class="text-body-secondary">The new role starts with no permissions. Tick them in the matrix afterwards.</p>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Create role</button>
                <a class="btn btn-outline-secondary" href="<?= url('/admin/roles') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
