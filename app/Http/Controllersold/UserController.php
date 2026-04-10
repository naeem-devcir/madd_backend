<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // ✅ GET ALL USERS
    public function index()
    {
        return response()->json(User::latest()->paginate(10));
    }

    // ✅ CREATE USER
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'nullable|min:6',
            'first_name' => 'required',
            'last_name' => 'required',
            'country_code' => 'required|integer',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user = User::create($validated);

        return response()->json([
            'message' => 'User created successfully',
            'data' => $user
        ], 201);
    }

    // ✅ GET SINGLE USER (UUID)
    public function show(User $user)
    {
        return response()->json($user);
    }

    // ✅ UPDATE USER
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'email' => 'email|unique:users,email,' . $user->id,
            'first_name' => 'sometimes|required',
            'last_name' => 'sometimes|required',
        ]);

        if ($request->filled('password')) {
            $validated['password'] = Hash::make($request->password);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully',
            'data' => $user
        ]);
    }

    // ✅ DELETE USER (Soft Delete)
    public function destroy(User $user)
    {
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }
}