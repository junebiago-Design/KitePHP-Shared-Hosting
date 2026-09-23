<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? 'Account') ?> · <?= e(config('app.name')) ?></title>
    <?php partial('partials/theme-init'); ?>
    <?= bootstrap_css() ?>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <?= render_css() ?>
</head>
<body class="bg-body-tertiary d-flex align-items-center min-vh-100 py-4">

<main class="auth-wrap container">
    <div class="text-center mb-3">
        <a class="fs-4 fw-semibold text-decoration-none" href="<?= url('/') ?>"><?= e(config('app.name')) ?></a>
    </div>

    <?php partial('partials/flash'); ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <?= $content ?>
        </div>
    </div>
</main>

<?= bootstrap_js() ?>
<script src="<?= asset('js/app.js') ?>"></script>
<?= render_js() ?>
</body>
</html>
