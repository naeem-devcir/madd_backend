<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Verify email address
     */
    public function verify(Request $request, $id, $hash)
    {
        // Find user
        $user = User::findOrFail($id);
        // $user = User::where('uuid', $uuid)->firstOrFail();

        // Verify hash
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification link',
            ], 400);
        }

        // Check if already verified
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Email already verified',
            ]);
        }

        // Mark email as verified
        $user->markEmailAsVerified();

        // ✅ UPDATE STATUS TO ACTIVE
        $user->status = 'active';
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully. Your account is now active.',
        ]);
    }

    /**
     * Resend verification email
     */
    public function resend(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email already verified',
            ], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Verification link sent to your email',
        ]);
    }
}

