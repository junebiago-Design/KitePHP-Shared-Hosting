<?php
namespace App\Controllers;

use App\Models\User;
use Core\Auth;
use Core\Controller;
use Core\Response;
use Core\Session;
use Core\ValidationException;

class AuthController extends Controller
{
    public function showLogin(): Response
    {
        return $this->view('auth/login', ['title' => 'Log in'], 'layouts/auth');
    }

    public function login(): Response
    {
        $data = $this->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($data['email'], $data['password'])) {
            Session::flash('errors', ['email' => ['These credentials do not match our records.']]);
            Session::flash('old', ['email' => $data['email']]);
            return $this->redirect('/login');
        }

        // Return to the page they originally wanted (internal paths only)
        $to = Session::get('intended');
        Session::forget('intended');
        if (!$to || $to[0] !== '/' || strpos($to, '//') === 0) {
            $to = config('auth.home', '/');
        }

        Session::flash('success', 'Welcome back, ' . Auth::user()->name . '!');
        return $this->redirect($to);
    }

    public function showRegister(): Response
    {
        return $this->view('auth/register', ['title' => 'Create account'], 'layouts/auth');
    }

    public function register(): Response
    {
        $data = $this->validate([
            'name'     => 'required|min:2|max:80',
            'email'    => 'required|email|max:120',
            'password' => 'required|min:8|confirmed',
        ]);

        $email = strtolower(trim($data['email']));
        if (User::first(['email' => $email])) {
            throw new ValidationException(['email' => ['That email is already registered.']]);
        }

        $user = new User([
            'name'     => trim($data['name']),
            'email'    => $email,
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
        ]);
        // The very first account becomes the admin; everyone else gets the default role
        $user->role = User::count() === 0 ? 'admin' : config('auth.default_role', 'user');
        $user->save();

        Auth::login($user);
        Session::flash('success', 'Account created. Welcome, ' . $user->name . '!');
        return $this->redirect(config('auth.home', '/'));
    }

    public function logout(): Response
    {
        Auth::logout();
        Session::flash('success', 'You have been logged out.');
        return $this->redirect('/');
    }
}
