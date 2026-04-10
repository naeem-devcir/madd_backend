<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserRoleController extends Controller
{
    public function assignRole(Request $request, $userUuid): JsonResponse
    {
        if (! auth()->check()) {
            return response()->json([
                'message' => 'Authentication is required to assign a role.'
            ], 401);
        }

        $request->validate([
            'role_id' => 'required|exists:roles,id'
        ]);

        $user = User::where('uuid', $userUuid)->firstOrFail();

        $user->assignRole(
            $request->role_id,
            auth()->id()
        );

        return response()->json([
            'message' => 'Role assigned successfully'
        ]);
    }
}
