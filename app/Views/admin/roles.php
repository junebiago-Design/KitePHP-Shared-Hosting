<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h2 mb-1">Roles &amp; permissions</h1>
        <p class="text-body-secondary mb-0">Tick what each role may do, then save. Changes apply on the user's next click.</p>
    </div>
    <a class="btn btn-primary" href="<?= url('/admin/roles/create') ?>">Add role</a>
</div>

<h2 class="h5">Permission matrix</h2>
<form method="post" action="<?= url('/admin/roles/permissions') ?>" class="mb-5">
    <?= csrf_field() ?>
    <?= method_field('PUT') ?>

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center mb-0 matrix">
                <thead>
                    <tr>
                        <th class="text-start">Permission</th>
                        <?php foreach ($roles as $role): ?>
                            <th scope="col">
                                <?= e($role->label) ?><br>
                                <span class="small fw-normal text-body-secondary"><?= e($role->name) ?></span>
                                <?php if ($role->isLocked()): ?><br><span class="badge text-bg-primary">Full access</span><?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($catalog as $group => $permissions): ?>
                    <tr class="table-active">
                        <td colspan="<?= count($roles) + 1 ?>" class="text-start fw-semibold text-uppercase small"><?= e($group) ?></td>
                    </tr>
                    <?php foreach ($permissions as $name => $label): ?>
                        <tr>
                            <td class="text-start"><?= e($label) ?> <code class="small"><?= e($name) ?></code></td>
                            <?php foreach ($roles as $role): ?>
                                <td>
                                    <input class="form-check-input" type="checkbox"
                                           name="perms[<?= (int) $role->id ?>][]"
                                           value="<?= e($name) ?>"
                                           aria-label="<?= e($role->label . ': ' . $label) ?>"
                                           <?= in_array($name, $assigned[$role->id], true) ? 'checked' : '' ?>
                                           <?= $role->isLocked() ? 'disabled' : '' ?>>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <button class="btn btn-primary" type="submit">Save permissions</button>
</form>

<h2 class="h5">Roles</h2>
<div class="card mb-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Role</th><th>Key</th><th>Users</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($roles as $role): ?>
                <tr>
                    <td><?= e($role->label) ?> <?php if ($role->isLocked()): ?><span class="badge text-bg-primary">Locked</span><?php endif; ?></td>
                    <td><code><?= e($role->name) ?></code></td>
                    <td><?= (int) $role->userCount() ?></td>
                    <td class="text-end">
                        <?php if (!$role->isLocked()): ?>
                            <form method="post" action="<?= url('/admin/roles/' . $role->id) ?>" class="d-inline"
                                  data-confirm="Delete the <?= e($role->label) ?> role?">
                                <?= csrf_field() ?>
                                <?= method_field('DELETE') ?>
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="text-body-secondary small">Locked roles always have full access and can't be edited or deleted, so you can never lock everyone out.
    A role can only be deleted when no users have it.</p>
