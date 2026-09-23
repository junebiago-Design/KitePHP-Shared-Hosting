<div class="text-center py-5">
    <h1 class="display-1 fw-bold text-body-secondary"><?= e($status) ?></h1>
    <p class="lead"><?= e($message) ?></p>

    <?php if (!empty($exception)): ?>
        <pre class="text-start bg-body-tertiary border rounded p-3 small overflow-auto"><?= e(get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine()) ?></pre>
    <?php endif; ?>

    <a class="btn btn-primary" href="<?= url('/') ?>">Back home</a>
</div>
