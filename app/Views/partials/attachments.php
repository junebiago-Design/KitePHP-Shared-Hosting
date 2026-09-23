<?php
/**
 * A grid of uploaded files with Preview / Download / Delete buttons.
 * Expects: $attachments (array of App\Models\Attachment)
 * Optional: $insertable = true on the post form: adds "Insert" buttons that put a Markdown
 *           link/image into the body, and Remove buttons that submit hidden forms (att-del-ID).
 */
$insertable = $insertable ?? false;
?>
<div class="row g-3">
<?php foreach ($attachments as $a): ?>
    <?php
        $view = url('/files/' . $a->id . '/view');
        $download = url('/files/' . $a->id . '/download');
        $canPreview = \Core\Uploader::previewable((string) $a->ext);
        $canDelete = can('files.delete') || ($a->post_id && can('posts.edit'));
        $label = str_replace(['[', ']'], '', (string) $a->original_name);
        $snippet = ($a->isImage() ? '!' : '') . '[' . $label . '](' . $view . ')';
        $previewAttrs = 'data-preview data-name="' . e($a->original_name) . '" data-ext="' . e($a->ext) . '"'
                      . ' data-view="' . e($view) . '" data-download="' . e($download) . '"';
    ?>
    <div class="col-sm-6 col-lg-4">
        <div class="card h-100">
            <?php if ($a->isImage()): ?>
                <a href="<?= e($view) ?>" <?= $previewAttrs ?>>
                    <img src="<?= e($view) ?>" class="card-img-top attachment-thumb" alt="<?= e($label) ?>" loading="lazy">
                </a>
            <?php else: ?>
                <div class="attachment-icon" aria-hidden="true"><?= file_icon($a->kind) ?></div>
            <?php endif; ?>

            <div class="card-body pb-2">
                <div class="fw-semibold text-truncate" title="<?= e($a->original_name) ?>"><?= e($a->original_name) ?></div>
                <div class="small text-body-secondary">
                    <?= e(strtoupper($a->ext)) ?> · <?= e($a->humanSize()) ?>
                    <?php if (!$a->post_id): ?> · library<?php endif; ?>
                </div>
            </div>

            <div class="card-footer bg-transparent border-0 pt-0 d-flex flex-wrap gap-2">
                <?php if ($canPreview): ?>
                    <button type="button" class="btn btn-sm btn-primary" <?= $previewAttrs ?>>Preview</button>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e($download) ?>">Download</a>

                <?php if ($insertable): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-insert-md="<?= e($snippet) ?>">Insert</button>
                <?php endif; ?>

                <?php if ($canDelete): ?>
                    <?php if ($insertable): ?>
                        <button type="submit" form="att-del-<?= (int) $a->id ?>" class="btn btn-sm btn-outline-danger">Remove</button>
                    <?php else: ?>
                        <form method="post" action="<?= url('/files/' . $a->id) ?>" data-confirm="Delete <?= e($a->original_name) ?>?">
                            <?= csrf_field() ?>
                            <?= method_field('DELETE') ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
