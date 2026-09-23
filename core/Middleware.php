<?php
namespace Core;

/**
 * Route middleware. Each method returns a function that receives the Request and
 * returns a Response to stop the request, or null to let it continue.
 *
 *   $router->get('/dashboard', 'DashboardController@index', [Middleware::auth()]);
 */
class Middleware
{
    /** Must be logged in. */
    public static function auth(): callable
    {
        return function (Request $request) {
            if (Auth::check()) {
                return null;
            }
            if ($request->expectsJson()) {
                return Response::json(['error' => 'Unauthenticated'], 401);
            }
            if ($request->method() === 'GET') {
                Session::put('intended', $request->path());   // come back here after login
            }
            Session::flash('error', 'Please log in to continue.');
            return Response::redirect('/login');
        };
    }

    /** Must NOT be logged in (login / register pages). */
    public static function guest(): callable
    {
        return function (Request $request) {
            return Auth::check() ? Response::redirect(config('auth.home', '/')) : null;
        };
    }

    /** Public registration allowed? Otherwise the page does not exist (404). */
    public static function registration(): callable
    {
        return function (Request $request) {
            if (!registration_open()) {
                throw new HttpException(404);
            }
            return null;
        };
    }

    /** Must be logged in AND have one of these roles. */
    public static function role(string ...$roles): callable
    {
        $auth = self::auth();
        return function (Request $request) use ($auth, $roles) {
            $stop = $auth($request);
            if ($stop) {
                return $stop;
            }
            if (!Auth::hasRole(...$roles)) {
                throw new HttpException(403, 'You do not have permission to access this page.');
            }
            return null;
        };
    }

    /** Must be logged in AND the role must grant this permission. */
    public static function can(string $permission): callable
    {
        $auth = self::auth();
        return function (Request $request) use ($auth, $permission) {
            $stop = $auth($request);
            if ($stop) {
                return $stop;
            }
            if (!Auth::can($permission)) {
                throw new HttpException(403, 'You do not have permission to do that.');
            }
            return null;
        };
    }
    
}
