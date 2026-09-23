<?php
namespace Core;

class App
{
    public static $config = [];

    public function __construct()
    {
        self::$config = require BASE_PATH . '/config/config.php';
        date_default_timezone_set(config('app.timezone', 'UTC'));

        if (config('app.debug')) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
        }
        Session::start();
    }

    public function run(): void
    {
        $request = new Request();

        try {
            $router = new Router();
            require BASE_PATH . '/routes/web.php';   // defines routes on $router
            $response = $router->dispatch($request);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                $response = Response::json(['errors' => $e->errors], 422);
            } else {
                Session::flash('errors', $e->errors);
                Session::flash('old', array_diff_key($request->all(), array_flip(['password', 'password_confirmation', '_token'])));
                $response = Response::redirect($_SERVER['HTTP_REFERER'] ?? '/');
            }
        } catch (HttpException $e) {
            $response = $this->errorResponse($request, $e->status(), $e->getMessage(), $e);
        } catch (\Throwable $e) {
            $response = $this->errorResponse($request, 500, 'Server Error', $e);
        }

        $response->send();
    }

    private function errorResponse(Request $request, int $status, string $message, \Throwable $e): Response
    {
        $debug = config('app.debug');
        if ($request->expectsJson()) {
            $payload = ['error' => $message];
            if ($debug && $status >= 500) {
                $payload['detail'] = $e->getMessage();
            }
            return Response::json($payload, $status);
        }
        try {
            $html = View::render('errors/error', [
                'title'     => (string) $status,
                'status'    => $status,
                'message'   => $message,
                'exception' => ($debug && $status >= 500) ? $e : null,
            ]);
        } catch (\Throwable $t) {
            $html = '<h1>' . $status . '</h1><p>' . htmlspecialchars($message) . '</p>';
        }
        return Response::html($html, $status);
    }
}
