<?php
namespace Core;

class Request
{
    private $query = [];
    private $body = [];
    private $params = [];

    public function __construct()
    {
        $q = $_GET;
        unset($q['route']);
        $this->query = $q;
        $this->body = $_POST;

        // Parse JSON bodies regardless of Content-Type. Some shared hosts
        // (InfinityFree's WAF) block application/json, so clients can send
        // text/plain with a JSON string and it still works.
        if (!$_POST) {
            $raw = file_get_contents('php://input');
            $t = ltrim((string) $raw);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $this->body = $json;
                }
            }
        }
    }

    public static function basePath(): string
    {
        return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    }

    public function method(): string
    {
        $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($m === 'POST') {
            $o = $this->body['_method'] ?? ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null);
            if ($o && in_array(strtoupper($o), ['PUT', 'PATCH', 'DELETE'], true)) {
                $m = strtoupper($o);
            }
        }
        return $m;
    }

    public function path(): string
    {
        if (isset($_GET['route'])) {
            $path = (string) $_GET['route'];
        } else {
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $base = self::basePath();
            if ($base !== '' && strpos($uri, $base) === 0) {
                $uri = substr($uri, strlen($base));
            }
            $path = preg_replace('#^/index\.php#', '', $uri);
        }
        return '/' . trim(rawurldecode($path), '/');
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function input(string $key, $default = null)
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    public function only(array $keys): array
    {
        $all = $this->all();
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $all)) {
                $out[$k] = $all[$k];
            }
        }
        return $out;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function file(string $key)
    {
        return $_FILES[$key] ?? null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With') ?? '') === 'xmlhttprequest';
    }

    public function expectsJson(): bool
    {
        return $this->isAjax()
            || strpos($this->header('Accept') ?? '', 'application/json') !== false
            || strpos($this->path(), '/api/') === 0;
    }

    public function setParams(array $p): void
    {
        $this->params = $p;
    }

    public function param(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }
}
