<?php
namespace Core;

class Router
{
    private $routes = [];
    private $prefix = '';
    private $groupMiddleware = [];

    public function get(string $path, $handler, array $mw = []): void    { $this->add('GET', $path, $handler, $mw); }
    public function post(string $path, $handler, array $mw = []): void   { $this->add('POST', $path, $handler, $mw); }
    public function put(string $path, $handler, array $mw = []): void    { $this->add('PUT', $path, $handler, $mw); }
    public function patch(string $path, $handler, array $mw = []): void  { $this->add('PATCH', $path, $handler, $mw); }
    public function delete(string $path, $handler, array $mw = []): void { $this->add('DELETE', $path, $handler, $mw); }
    public function any(string $path, $handler, array $mw = []): void    { $this->add('ANY', $path, $handler, $mw); }

    public function group(string $prefix, callable $callback, array $mw = []): void
    {
        $prevPrefix = $this->prefix;
        $prevMw = $this->groupMiddleware;
        $this->prefix .= '/' . trim($prefix, '/');
        $this->groupMiddleware = array_merge($this->groupMiddleware, $mw);
        $callback($this);
        $this->prefix = $prevPrefix;
        $this->groupMiddleware = $prevMw;
    }

    private function add(string $method, string $path, $handler, array $mw): void
    {
        $full = '/' . trim(preg_replace('#/+#', '/', $this->prefix . '/' . $path), '/');
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $full);
        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'handler' => $handler,
            'mw'      => array_merge($this->groupMiddleware, $mw),
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();
        $methodMismatch = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                $methodMismatch = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $request->setParams($params);
            return $this->run($route, $request, array_values($params));
        }

        throw new HttpException($methodMismatch ? 405 : 404);
    }

    private function run(array $route, Request $request, array $args): Response
    {
        $this->checkUploadSize();
        $this->checkCsrf($request);

        foreach ($route['mw'] as $mw) {
            $res = $mw($request);
            if ($res instanceof Response) {
                return $res;
            }
        }

        $handler = $route['handler'];
        if (is_string($handler) && strpos($handler, '@') !== false) {
            list($class, $action) = explode('@', $handler, 2);
            $handler = ['App\\Controllers\\' . $class, $action];
        }

        if (is_array($handler)) {
            list($class, $action) = $handler;
            if (!class_exists($class) || !method_exists($class, $action)) {
                throw new HttpException(500, "Handler not found: $class::$action");
            }
            $controller = new $class($request);
            $result = $controller->$action(...$args);
        } else {
            $result = $handler($request, ...$args);
        }

        return $this->toResponse($result);
    }

    /**
     * When a form upload is bigger than PHP's post_max_size, PHP silently empties $_POST and
     * $_FILES. Without this check the visitor would just see a confusing "419 Page Expired".
     */
    private function checkUploadSize(): void
    {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && empty($_POST) && empty($_FILES)
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
            && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0
        ) {
            throw new HttpException(413, 'The upload is too large for this server (limit ' . ini_get('post_max_size') . '). Try smaller files.');
        }
    }

    private function checkCsrf(Request $request): void
    {
        if (!config('app.csrf', true)) {
            return;
        }
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        $path = ltrim($request->path(), '/');
        foreach ((array) config('app.csrf_except', []) as $pattern) {
            if (fnmatch($pattern, $path)) {
                return;
            }
        }
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');
        if (!Session::verifyCsrf($token)) {
            throw new HttpException(419);
        }
    }

    private function toResponse($result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }
}
