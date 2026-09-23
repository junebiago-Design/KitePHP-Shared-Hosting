<?php
namespace App\Controllers;

use App\Models\Role;
use App\Models\User;
use Core\Auth;
use Core\Controller;
use Core\HttpException;
use Core\Response;
use Core\Session;
use Core\ValidationException;

/** Routes need the 'users.manage' permission (see routes/web.php). */
class AdminController extends Controller
{
    /** Roles this admin may hand out. Only full-access admins can assign locked roles. */
    private function roleNames(): array
    {
        return Role::names(Auth::isSuper());
    }

    /** Non-super admins can't touch accounts that hold a locked (full-access) role. */
    private function guard(User $user): void
    {
        if (!Auth::isSuper() && in_array($user->role, Role::lockedNames(), true)) {
            throw new HttpException(403, 'Only a full-access admin can manage this account.');
        }
    }

    public function users(): Response
    {
        return $this->view('admin/users', [
            'title' => 'Manage users',
            'users' => User::all('id ASC'),
            'roles' => $this->roleNames(),
            'isSuper'     => Auth::isSuper(),
            'lockedRoles' => Role::lockedNames(),
        ]);
    }

    public function create(): Response
    {
        return $this->view('admin/user-form', [
            'title'  => 'Add user',
            'user'   => null,
            'roles'  => $this->roleNames(),
            'isSelf' => false,
        ]);
    }

    public function store(): Response
    {
        $data = $this->validate([
            'name'     => 'required|min:2|max:80',
            'email'    => 'required|email|max:120',
            'role'     => 'required|in:' . implode(',', $this->roleNames()),
            'password' => 'required|min:8|confirmed',
        ]);

        $email = strtolower(trim($data['email']));
        if (User::first(['email' => $email])) {
            throw new ValidationException(['email' => ['That email is already in use.']]);
        }

        $user = new User([
            'name'     => trim($data['name']),
            'email'    => $email,
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
        ]);
        $user->role = $data['role'];
        $user->save();

        Session::flash('success', $user->name . ' was added as ' . $user->role . '.');
        return $this->redirect('/admin/users');
    }

    public function edit($id): Response
    {
        $user = User::findOrFail($id);
        $this->guard($user);
        return $this->view('admin/user-form', [
            'title'  => 'Edit user',
            'user'   => $user,
            'roles'  => $this->roleNames(),
            'isSelf' => (int) $user->id === Auth::id(),
        ]);
    }

    public function update($id): Response
    {
        $user = User::findOrFail($id);
        $this->guard($user);
        $isSelf = (int) $user->id === Auth::id();

        $rules = [
            'name'     => 'required|min:2|max:80',
            'email'    => 'required|email|max:120',
            'password' => 'min:8|confirmed',      // optional: blank keeps the current password
        ];
        if (!$isSelf) {
            $rules['role'] = 'required|in:' . implode(',', $this->roleNames());
        }
        $data = $this->validate($rules);

        $email = strtolower(trim($data['email']));
        $other = User::first(['email' => $email]);
        if ($other && (int) $other->id !== (int) $user->id) {
            throw new ValidationException(['email' => ['That email is already in use.']]);
        }

        $user->name = trim($data['name']);
        $user->email = $email;
        if (!$isSelf) {
            $user->role = $data['role'];          // you can't change your own role
        }
        if (($data['password'] ?? '') !== '') {
            $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        $user->save();

        Session::flash('success', $user->name . ' was updated.');
        return $this->redirect('/admin/users');
    }

    public function updateRole($id): Response
    {
        $data = $this->validate(['role' => 'required|in:' . implode(',', $this->roleNames())]);
        $user = User::findOrFail($id);
        $this->guard($user);

        if ((int) $user->id === Auth::id()) {
            Session::flash('error', 'You cannot change your own role.');
            return $this->redirect('/admin/users');
        }

        $user->role = $data['role'];
        $user->save();
        Session::flash('success', $user->name . ' is now ' . $data['role'] . '.');
        return $this->redirect('/admin/users');
    }

    public function destroy($id): Response
    {
        $user = User::findOrFail($id);
        $this->guard($user);

        if ((int) $user->id === Auth::id()) {
            Session::flash('error', 'You cannot delete your own account.');
            return $this->redirect('/admin/users');
        }

        $user->delete();
        Session::flash('success', 'User deleted.');
        return $this->redirect('/admin/users');
    }
}
