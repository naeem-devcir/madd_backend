<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    // ✅ GET ALL ROLES
    public function index()
    {
        return response()->json(Role::latest()->get());
    }

    // ✅ CREATE ROLE
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|unique:roles,name',
            'display_name' => 'required',
            'description' => 'nullable',
        ]);

        $role = Role::create($validated);

        return response()->json([
            'message' => 'Role created successfully',
            'data' => $role
        ], 201);
    }

    // ✅ GET SINGLE ROLE
    public function show($id)
    {
        $role = Role::findOrFail($id);

        return response()->json($role);
    }

    // ✅ UPDATE ROLE
    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|unique:roles,name,' . $id,
            'display_name' => 'required',
            'description' => 'nullable',
        ]);

        $role->update($validated);

        return response()->json([
            'message' => 'Role updated successfully',
            'data' => $role
        ]);
    }

    // ✅ DELETE ROLE
    public function destroy($id)
    {
        $role = Role::findOrFail($id);
        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully'
        ]);
    }
}