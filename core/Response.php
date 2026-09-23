<?php
namespace Core;

class Response
{
    public static $texts = [
        200 => 'OK', 201 => 'Created', 204 => 'No Content',
        302 => 'Found', 400 => 'Bad Request', 403 => 'Forbidden',
        404 => 'Page Not Found', 405 => 'Method Not Allowed',
        413 => 'Upload Too Large',
        419 => 'Page Expired (invalid CSRF token)', 422 => 'Unprocessable Entity',
        500 => 'Server Error',
    ];

    private $body;
    private $status;
    private $headers;
    private $file = null;   // path of a file to stream instead of $body

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json($data, int $status = 200): self
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
        return new self(json_encode($data, $flags), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        if (!preg_match('#^https?://#', $to)) {
            $to = url($to);
        }
        return new self('', $status, ['Location' => $to]);
    }

    /** Stream a file from disk (used for uploads, which live outside the public web folder). */
    public static function file(string $path, string $mime, string $name, bool $inline = true): self
    {
        $safe = preg_replace('/[\x00-\x1f\x7f"\\\\]+/', '_', $name);
        $r = new self('', 200, [
            'Content-Type'           => $mime,
            'Content-Length'         => (string) filesize($path),
            'Content-Disposition'    => ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=3600',
        ]);
        $r->file = $path;
        return $r;
    }

    public function header(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }

        if ($this->file !== null) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();      // don't hold the session lock while streaming
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($this->file);
            return;
        }

        echo $this->body;
    }
}
