<?php
add_js('js/markdown.js');
add_js('js/file-preview.js');
$maxSize = human_size(\Core\Uploader::maxBytes());
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h2 mb-1">Files</h1>
        <p class="text-body-secondary mb-0"><?= (int) $total ?> file<?= $total == 1 ? '' : 's' ?> · click Preview to open one in the browser</p>
    </div>
</div>

<?php if (can('files.upload')): ?>
    <div class="card mb-4">
        <div class="card-body">
            <form method="post" action="<?= url('/files') ?>" enctype="multipart/form-data" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-8">
                    <label for="files" class="form-label">Upload files</label>
                    <input class="form-control" type="file" id="files" name="files[]" multiple
                           accept="<?= e(\Core\Uploader::acceptAttr()) ?>" required>
                </div>
                <div class="col-md-4 d-grid">
                    <button class="btn btn-primary" type="submit">Upload</button>
                </div>
                <div class="col-12 form-text mt-1">
                    Up to <?= (int) config('uploads.max_files', 10) ?> files at a time, <?= e($maxSize) ?> each.
                    Allowed:
                    <?php foreach (\Core\Uploader::groups() as $label => $exts): ?>
                        <strong><?= e($label) ?></strong> (<?= e(implode(', ', $exts)) ?>)
                    <?php endforeach; ?>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (!$data): ?>
    <div class="alert alert-secondary">No files yet.</div>
<?php else: ?>
    <?php partial('partials/attachments', ['attachments' => $data]); ?>
<?php endif; ?>

<?php if ($pages > 1): ?>
    <nav aria-label="Files pages" class="mt-4">
        <ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <li class="page-item<?= $i === $page ? ' active' : '' ?>">
                    <a class="page-link" href="<?= url('/files?page=' . $i) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
