<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h2 mb-1">Manage users</h1>
        <p class="text-body-secondary mb-0">Add, edit or remove accounts and choose their role.</p>
    </div>
    <a class="btn btn-primary" href="<?= url('/admin/users/create') ?>">Add user</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php
                    $isMe = (int) $u->id === (int) auth_user()->id;
                    // Only full-access admins may manage accounts that hold a locked role
                    $canTouch = $isSuper || !in_array($u->role, $lockedRoles, true);
                ?>
                <tr>
                    <td><?= e($u->name) ?><?= $isMe ? ' <span class="text-body-secondary small">(you)</span>' : '' ?></td>
                    <td><?= e($u->email) ?></td>
                    <td>
                        <?php if ($canTouch): ?>
                            <form method="post" action="<?= url('/admin/users/' . $u->id . '/role') ?>" class="d-flex gap-2">
                                <?= csrf_field() ?>
                                <select name="role" class="form-select form-select-sm w-auto" aria-label="Role for <?= e($u->name) ?>" <?= $isMe ? 'disabled' : '' ?>>
                                    <?php foreach ($roles as $r): ?>
                                        <option value="<?= e($r) ?>" <?= $u->role === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$isMe): ?><button class="btn btn-sm btn-outline-primary" type="submit">Save</button><?php endif; ?>
                            </form>
                        <?php else: ?>
                            <?= role_badge($u->role) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($canTouch): ?>
                            <div class="d-inline-flex gap-2">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= url('/admin/users/' . $u->id . '/edit') ?>">Edit</a>
                                <?php if (!$isMe): ?>
                                    <form method="post" action="<?= url('/admin/users/' . $u->id) ?>" data-confirm="Delete <?= e($u->name) ?>? This can't be undone.">
                                        <?= csrf_field() ?>
                                        <?= method_field('DELETE') ?>
                                        <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
