<?php
namespace Core;

/**
 * Safe file uploads.
 *  - files are stored OUTSIDE the public web folder (storage/uploads, blocked by .htaccess)
 *  - stored under a random name, never the name the visitor chose
 *  - only extensions from config('uploads.allowed') are accepted, and the file's real
 *    content (magic bytes) must match the extension
 *  - files are served through FileController, never directly by Apache
 */
class Uploader
{
    /** ext => [mime used when serving, content check, group, safe to display inline in the browser] */
    private static $types = [
        'jpg'  => ['image/jpeg', 'image', 'image', true],
        'jpeg' => ['image/jpeg', 'image', 'image', true],
        'png'  => ['image/png', 'image', 'image', true],
        'gif'  => ['image/gif', 'image', 'image', true],
        'webp' => ['image/webp', 'image', 'image', true],
        'pdf'  => ['application/pdf', 'pdf', 'pdf', true],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'zip', 'spreadsheet', false],
        'xls'  => ['application/vnd.ms-excel', 'ole', 'spreadsheet', false],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'zip', 'spreadsheet', false],
        'csv'  => ['text/plain; charset=utf-8', 'text', 'spreadsheet', true],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'zip', 'document', false],
        'doc'  => ['application/msword', 'ole', 'document', false],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'zip', 'document', false],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'zip', 'presentation', false],
        'ppt'  => ['application/vnd.ms-powerpoint', 'ole', 'presentation', false],
        'txt'  => ['text/plain; charset=utf-8', 'text', 'text', true],
        'md'   => ['text/plain; charset=utf-8', 'text', 'text', true],
    ];

    /** Types the browser preview (assets/js/file-preview.js) can show. */
    private static $previewable = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'xlsx', 'xls', 'ods', 'csv', 'docx', 'txt', 'md'];

    private static $groupLabels = [
        'image' => 'Images', 'pdf' => 'PDF', 'spreadsheet' => 'Spreadsheets',
        'document' => 'Documents', 'presentation' => 'Presentations', 'text' => 'Text',
    ];

    // ---------------------------------------------------------------- info

    /** Allowed extensions as [ext => true], from config('uploads.allowed'). */
    public static function allowed(): array
    {
        $out = [];
        foreach ((array) config('uploads.allowed', array_keys(self::$types)) as $ext) {
            $ext = strtolower(ltrim((string) $ext, '.'));
            if (isset(self::$types[$ext])) {
                $out[$ext] = true;
            }
        }
        return $out;
    }

    public static function allowedExts(): array
    {
        return array_keys(self::allowed());
    }

    /** Value for <input type="file" accept="...">. */
    public static function acceptAttr(): string
    {
        return '.' . implode(',.', self::allowedExts());
    }

    /** ['Images' => ['jpg', 'png'], 'PDF' => ['pdf'], ...] for help text. */
    public static function groups(): array
    {
        $out = [];
        foreach (self::allowedExts() as $ext) {
            $out[self::$groupLabels[self::$types[$ext][2]]][] = $ext;
        }
        return $out;
    }

    public static function servedMime(string $ext): string
    {
        return self::$types[strtolower($ext)][0] ?? 'application/octet-stream';
    }

    public static function group(string $ext): string
    {
        return self::$types[strtolower($ext)][2] ?? 'other';
    }

    public static function isInline(string $ext): bool
    {
        return self::$types[strtolower($ext)][3] ?? false;
    }

    public static function previewable(string $ext): bool
    {
        return in_array(strtolower($ext), self::$previewable, true);
    }

    /** Effective per-file limit: the smaller of the config value and PHP's own limits. */
    public static function maxBytes(): int
    {
        $limits = [(int) (config('uploads.max_size_mb', 5) * 1048576)];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = self::iniBytes($key);
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }
        return min($limits);
    }

    private static function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        if ($v === '') {
            return 0;
        }
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {   // intentional fall-through: g -> m -> k
            case 'g': $n *= 1024;
            case 'm': $n *= 1024;
            case 'k': $n *= 1024;
        }
        return $n;
    }

    // ------------------------------------------------------------- storage

    public static function dir(): string
    {
        $dir = BASE_PATH . '/' . trim((string) config('uploads.dir', 'storage/uploads'), '/');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Cannot create the uploads folder. Check permissions on storage/.');
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('The uploads folder is not writable. Set storage/ permissions to 755 or 775.');
        }
        return $dir;
    }

    public static function path(string $storedName): string
    {
        return BASE_PATH . '/' . trim((string) config('uploads.dir', 'storage/uploads'), '/') . '/' . basename($storedName);
    }

    public static function delete(string $storedName): void
    {
        $path = self::path($storedName);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------- uploads

    /**
     * Process $_FILES[$field] (single or multiple).
     * Returns ['saved' => [meta, ...], 'errors' => ['name: reason', ...]].
     * Each meta = original_name, stored_name, mime, ext, size, kind.
     */
    public static function handle(string $field): array
    {
        $result = ['saved' => [], 'errors' => []];
        if (empty($_FILES[$field]) || !isset($_FILES[$field]['name'])) {
            return $result;
        }

        $f = $_FILES[$field];
        $names = (array) $f['name'];
        $tmps  = (array) $f['tmp_name'];
        $errs  = (array) $f['error'];

        $allowed  = self::allowed();
        $max      = self::maxBytes();
        $maxFiles = max(1, (int) config('uploads.max_files', 10));
        $count    = 0;
        $dir      = null;

        foreach ($names as $i => $rawName) {
            $err = (int) ($errs[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $label = self::cleanName((string) $rawName);
            if (++$count > $maxFiles) {
                $result['errors'][] = "Only $maxFiles files can be uploaded at once.";
                break;
            }
            if ($err !== UPLOAD_ERR_OK) {
                $result['errors'][] = $label . ': ' . self::errorMessage($err);
                continue;
            }

            $tmp = (string) ($tmps[$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                $result['errors'][] = "$label: the upload could not be verified.";
                continue;
            }

            $size = (int) filesize($tmp);       // measured on the server, not trusted from the browser
            if ($size <= 0) {
                $result['errors'][] = "$label: the file is empty.";
                continue;
            }
            if ($size > $max) {
                $result['errors'][] = "$label: larger than the limit of " . human_size($max) . '.';
                continue;
            }

            $ext = strtolower(pathinfo((string) $rawName, PATHINFO_EXTENSION));
            if ($ext === '' || !isset($allowed[$ext])) {
                $result['errors'][] = $label . ': ' . ($ext === '' ? 'files without an extension' : ".$ext files") . ' are not allowed.';
                continue;
            }

            list($mime, $check, $group) = self::$types[$ext];
            if (!self::contentMatches($check, $ext, $tmp)) {
                $result['errors'][] = "$label: the content does not look like a real .$ext file.";
                continue;
            }

            try {
                $dir = $dir ?: self::dir();
            } catch (\RuntimeException $e) {
                $result['errors'][] = $e->getMessage();
                break;
            }

            $stored = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!@move_uploaded_file($tmp, $dir . '/' . $stored)) {
                $result['errors'][] = "$label: could not be saved on the server.";
                continue;
            }
            @chmod($dir . '/' . $stored, 0644);

            $result['saved'][] = [
                'original_name' => $label,
                'stored_name'   => $stored,
                'mime'          => $mime,
                'ext'           => $ext,
                'size'          => $size,
                'kind'          => $group,
            ];
        }

        return $result;
    }

    /** One-line summary of what went wrong, or null. */
    public static function errorText(array $result): ?string
    {
        return $result['errors'] ? implode(' ', $result['errors']) : null;
    }

    // ------------------------------------------------------------- helpers

    /** Checks the first bytes of the file, so a renamed .exe/.php can't pass as an image, etc. */
    private static function contentMatches(string $check, string $ext, string $tmp): bool
    {
        $fh = @fopen($tmp, 'rb');
        if (!$fh) {
            return false;
        }
        $head = (string) fread($fh, 4096);
        fclose($fh);

        switch ($check) {
            case 'image':
                $info = @getimagesize($tmp);
                if (!$info) {
                    return false;
                }
                $map = [
                    IMAGETYPE_JPEG => ['jpg', 'jpeg'],
                    IMAGETYPE_PNG  => ['png'],
                    IMAGETYPE_GIF  => ['gif'],
                    IMAGETYPE_WEBP => ['webp'],
                ];
                return isset($map[$info[2]]) && in_array($ext, $map[$info[2]], true);

            case 'pdf':
                $pos = strpos($head, '%PDF-');
                return $pos !== false && $pos < 1024;

            case 'zip':     // docx, xlsx, pptx, ods, odt are ZIP containers
                return substr($head, 0, 2) === 'PK';

            case 'ole':     // old binary Office formats: doc, xls, ppt
                return substr($head, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

            case 'text':    // plain text must not contain NUL bytes
                return strpos($head, "\0") === false;
        }
        return false;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[^\p{L}\p{N} ._()\[\]-]+/u', '_', $name);
        if ($clean === null) {      // invalid UTF-8
            $clean = preg_replace('/[^A-Za-z0-9 ._()\[\]-]+/', '_', $name);
        }
        $clean = trim((string) $clean);
        if (mb_strlen($clean) > 150) {
            $ext = pathinfo($clean, PATHINFO_EXTENSION);
            $clean = mb_substr(pathinfo($clean, PATHINFO_FILENAME), 0, 140) . ($ext !== '' ? '.' . $ext : '');
        }
        return $clean !== '' ? $clean : 'file';
    }

    private static function errorMessage(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'is larger than the server allows (max ' . human_size(self::maxBytes()) . ').';
            case UPLOAD_ERR_PARTIAL:
                return 'was only partly uploaded. Please try again.';
            default:
                return 'could not be saved on the server.';
        }
    }
}
