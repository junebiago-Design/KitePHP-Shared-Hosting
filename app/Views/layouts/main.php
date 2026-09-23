<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? config('app.name')) ?> · <?= e(config('app.name')) ?></title>
    <?php partial('partials/theme-init'); ?>
    <?= bootstrap_css() ?>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <?= render_css() ?>
</head>
<body class="d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-light navbar-light bg-dark bg-body-tertiary border-bottom sticky-top">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="<?= url('/') ?>">🚀<?= e(config('app.name')) ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link<?= nav_active('/') ?>" href="<?= url('/') ?>">Home</a></li>
                <li class="nav-item"><a class="nav-link<?= nav_active('/posts') ?>" href="<?= url('/guide/3') ?>">Guide</a></li>
                <li class="nav-item"><a class="nav-link<?= nav_active('/about') ?>" href="<?= url('/about') ?>">About</a></li>
                <?php if (can('files.view')): ?>
                    <li class="nav-item"><a class="nav-link<?= nav_active('/files') ?>" href="<?= url('/files') ?>">Files</a></li>
                <?php endif; ?>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <button type="button" id="theme-toggle" class="btn btn-outline-secondary btn-sm" aria-label="Toggle light/dark theme">🌙</button>

                <?php if ($me = auth_user()): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                                data-bs-toggle="dropdown" aria-expanded="false">
                            <?= e($me->name) ?> <?= role_badge($me->role) ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= url('/dashboard') ?>">Dashboard</a></li>
                            <?php if (can('users.manage')): ?>
                                <li><a class="dropdown-item" href="<?= url('/admin/users') ?>">Users</a></li>
                            <?php endif; ?>
                            <?php if (can('roles.manage')): ?>
                                <li><a class="dropdown-item" href="<?= url('/admin/roles') ?>">Roles &amp; permissions</a></li>
                            <?php endif; ?>
                            <?php if (can('roles.manage')): ?>
                                <li><a class="dropdown-item" href="<?= url('/database') ?>">Database Management</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="<?= url('/logout') ?>">
                                    <?= csrf_field() ?>
                                    <button class="dropdown-item" type="submit">Log out</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a class="btn btn-outline-primary btn-sm" href="<?= url('/login') ?>">Log in</a>
                    <?php if (config('app.debug')): ?>
                        <a class="btn btn-primary btn-sm" href="<?= url('/register') ?>">Register</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<main class="container py-4 flex-grow-1">
    <?php partial('partials/flash'); ?>
    <?= $content ?>
</main>

<footer class="border-top py-3 text-center text-body-secondary small">
    Built with <?= e(config('app.name')) ?> · PHP <?= e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?>
</footer>

<!-- Confirm dialog used by any form with data-confirm="..." (see assets/js/app.js) -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalLabel">Please confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="confirmModalBody">Are you sure?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmModalOk">Confirm</button>
            </div>
        </div>
    </div>
</div>

<?php partial('partials/preview-modal'); ?>

<?= bootstrap_js() ?>
<script src="<?= asset('js/app.js') ?>"></script>
<?= render_js() ?>
</body>
</html>
