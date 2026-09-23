<?php
namespace Core;

class Auth
{
    private static $user = false;        // false = not loaded yet, null = guest
    private static $roleCache = null;    // ['locked' => bool, 'names' => [permissions]]

    /** Check credentials and log the user in. */
    public static function attempt(string $email, string $password): bool
    {
        $class = config('auth.model');
        $user = $class::first(['email' => strtolower(trim($email))]);

        // Always run a password check so timing doesn't reveal which emails exist
        $hash = $user ? $user->password : '$2y$07$BCryptRequires22Chrcte/VlQH0piJtjXl.0t1XkA8pw9dMXTpOq';
        if (!password_verify($password, $hash) || !$user) {
            return false;
        }

        if (password_needs_rehash($user->password, PASSWORD_DEFAULT)) {
            $user->password = password_hash($password, PASSWORD_DEFAULT);
            $user->save();
        }

        self::login($user);
        return true;
    }

    public static function login($user): void
    {
        session_regenerate_id(true);        // prevents session fixation
        Session::forget('_csrf');           // fresh CSRF token
        Session::put('_user_id', (int) $user->id);
        self::$user = $user;
        self::$roleCache = null;
    }

    public static function logout(): void
    {
        Session::forget('_user_id');
        Session::forget('_csrf');
        session_regenerate_id(true);
        self::$user = false;
        self::$roleCache = null;
    }

    public static function user()
    {
        if (self::$user === false) {
            $id = Session::get('_user_id');
            self::$user = null;
            if ($id) {
                $class = config('auth.model');
                self::$user = $class::find($id);
                if (!self::$user) {
                    Session::forget('_user_id');   // account was deleted
                }
            }
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u->id : null;
    }

    public static function role(): ?string
    {
        $u = self::user();
        return $u ? (string) $u->role : null;
    }

    public static function hasRole(string ...$roles): bool
    {
        $role = self::role();
        return $role !== null && in_array($role, $roles, true);
    }

    /** Does the current user's role grant this permission? (RBAC) */
    public static function can(string $permission): bool
    {
        $set = self::roleAccess();
        if ($set === null) {
            return false;
        }
        return $set['locked'] || in_array($permission, $set['names'], true);
    }

    /** True for a locked, full-access role (admin). */
    public static function isSuper(): bool
    {
        $set = self::roleAccess();
        return $set !== null && $set['locked'];
    }

    /** Permission names for the current user; ['*'] means full access. */
    public static function permissionList(): array
    {
        $set = self::roleAccess();
        if ($set === null) {
            return [];
        }
        return $set['locked'] ? ['*'] : $set['names'];
    }

    /**
     * Loads the current role's permissions from the database once per request.
     * Falls back to config('roles') if the roles tables can't be read.
     */
    private static function roleAccess(): ?array
    {
        $role = self::role();
        if ($role === null) {
            return null;
        }
        if (self::$roleCache === null) {
            try {
                $class = config('auth.role_model');
                $row = $class::first(['name' => $role]);
                self::$roleCache = $row
                    ? ['locked' => $row->isLocked(), 'names' => $row->permissions()]
                    : ['locked' => false, 'names' => []];
            } catch (\Throwable $e) {
                $granted = (array) config('roles.' . $role, []);
                self::$roleCache = ['locked' => in_array('*', $granted, true), 'names' => $granted];
            }
        }
        return self::$roleCache;
    }
}
