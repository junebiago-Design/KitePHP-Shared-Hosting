<h1 class="h2">Dashboard</h1>
<p class="text-body-secondary">
    Signed in as <strong><?= e($user->name) ?></strong> (<?= e($user->email) ?>) <?= role_badge($user->role) ?>
</p>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 card-title">Your permissions</h2>
        <p class="card-text mb-0">
            <?php if (!$permissions): ?>
                <span class="text-body-secondary">None assigned to this role yet.</span>
            <?php endif; ?>
            <?php foreach ($permissions as $perm): ?>
                <span class="badge text-bg-light border font-monospace"><?= e($perm === '*' ? 'everything' : $perm) ?></span>
            <?php endforeach; ?>
        </p>
    </div>
</div>

<h2 class="h5 mb-3">What you can do</h2>
<div class="row g-3">
    <div class="col-md-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <h3 class="h6 card-title">Create posts</h3>
            <?php if (can('posts.create')): ?>
                <a class="btn btn-primary btn-sm" href="<?= url('/posts/create') ?>">New post</a>
            <?php else: ?>
                <p class="card-text text-body-secondary mb-0">Not allowed for your role.</p>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <h3 class="h6 card-title">Edit posts</h3>
            <p class="card-text mb-0 <?= can('posts.edit') ? '' : 'text-body-secondary' ?>"><?= can('posts.edit') ? 'Allowed: use the Edit button on any post.' : 'Not allowed for your role.' ?></p>
        </div></div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <h3 class="h6 card-title">Delete posts</h3>
            <p class="card-text mb-0 <?= can('posts.delete') ? '' : 'text-body-secondary' ?>"><?= can('posts.delete') ? 'Allowed: use the Delete button on any post.' : 'Not allowed for your role.' ?></p>
        </div></div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card h-100"><div class="card-body">
            <h3 class="h6 card-title">Administration</h3>
            <?php if (can('users.manage') || can('roles.manage')): ?>
                <?php if (can('users.manage')): ?><a class="btn btn-primary btn-sm" href="<?= url('/admin/users') ?>">Users</a><?php endif; ?>
                <?php if (can('roles.manage')): ?><a class="btn btn-outline-primary btn-sm" href="<?= url('/admin/roles') ?>">Roles</a><?php endif; ?>
            <?php else: ?>
                <p class="card-text text-body-secondary mb-0">Not allowed for your role.</p>
            <?php endif; ?>
        </div></div>
    </div>
</div>
