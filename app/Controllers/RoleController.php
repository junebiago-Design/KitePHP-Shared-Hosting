<?php
namespace App\Controllers;

use App\Models\Role;
use Core\Controller;
use Core\Response;
use Core\Session;
use Core\ValidationException;

/** Roles and permissions page. Routes need the 'roles.manage' permission. */
class RoleController extends Controller
{
    public function index(): Response
    {
        $roles = Role::all('id ASC');
        $assigned = [];
        foreach ($roles as $role) {
            $assigned[$role->id] = $role->isLocked() ? Role::catalogNames() : $role->permissions();
        }
        return $this->view('admin/roles', [
            'title'    => 'Roles & permissions',
            'roles'    => $roles,
            'catalog'  => Role::catalog(),
            'assigned' => $assigned,
        ]);
    }

    public function create(): Response
    {
        return $this->view('admin/role-form', ['title' => 'Add role']);
    }

    public function store(): Response
    {
        $data = $this->validate([
            'name'  => 'required|min:2|max:30',
            'label' => 'required|max:60',
        ]);

        $name = strtolower(trim($data['name']));
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new ValidationException(['name' => ['Use lowercase letters, numbers, - or _, starting with a letter.']]);
        }
        if (Role::first(['name' => $name])) {
            throw new ValidationException(['name' => ['That role already exists.']]);
        }

        $role = new Role(['name' => $name, 'label' => trim($data['label'])]);
        $role->is_locked = 0;
        $role->save();

        Session::flash('success', 'Role created. Tick its permissions below and save.');
        return $this->redirect('/admin/roles');
    }

    /** Saves the whole permission matrix (one column per role). */
    public function savePermissions(): Response
    {
        $posted = $this->request->input('perms', []);
        $posted = is_array($posted) ? $posted : [];
        $valid = Role::catalogNames();

        foreach (Role::all('id ASC') as $role) {
            if ($role->isLocked()) {
                continue;   // full-access roles are not editable
            }
            $chosen = isset($posted[$role->id]) && is_array($posted[$role->id]) ? $posted[$role->id] : [];
            $chosen = array_filter($chosen, 'is_string');
            $role->syncPermissions(array_values(array_intersect($chosen, $valid)));
        }

        Session::flash('success', 'Permissions saved.');
        return $this->redirect('/admin/roles');
    }

    public function destroy($id): Response
    {
        $role = Role::findOrFail($id);

        if ($role->isLocked()) {
            Session::flash('error', 'The ' . $role->label . ' role is locked and cannot be deleted.');
            return $this->redirect('/admin/roles');
        }
        if (($n = $role->userCount()) > 0) {
            Session::flash('error', $n . ' user(s) still have the ' . $role->label . ' role. Move them to another role first.');
            return $this->redirect('/admin/roles');
        }

        $role->delete();
        Session::flash('success', 'Role deleted.');
        return $this->redirect('/admin/roles');
    }
}
