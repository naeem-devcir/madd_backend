<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    // GET ALL
    public function index()
    {
        return response()->json(Permission::all());
    }

    // STORE
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|unique:permissions,name',
            'display_name' => 'required|max:150',
            'permission_description' => 'required',
            'module' => 'required|max:100',
            'guard_name' => 'nullable|max:100',
            'is_system' => 'boolean',
        ]);

        $permission = Permission::create($validated);

        return response()->json($permission, 201);
    }

    // SHOW SINGLE
    public function show($id)
    {
        $permission = Permission::findOrFail($id);
        return response()->json($permission);
    }

    // UPDATE
    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);

        if ($permission->is_system) {
            return response()->json(['error' => 'System permission cannot be updated'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|unique:permissions,name,' . $id,
            'display_name' => 'sometimes|max:150',
            'permission_description' => 'sometimes',
            'module' => 'sometimes|max:100',
            'guard_name' => 'nullable|max:100',
            'is_system' => 'boolean',
        ]);

        $permission->update($validated);

        return response()->json($permission);
    }

    // DELETE
    public function destroy($id)
    {
        $permission = Permission::findOrFail($id);

        if ($permission->is_system) {
            return response()->json(['error' => 'System permission cannot be deleted'], 403);
        }

        $permission->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}