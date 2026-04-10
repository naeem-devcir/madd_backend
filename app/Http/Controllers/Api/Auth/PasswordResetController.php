<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;


class PasswordResetController extends Controller
{
    /**
     * Send password reset link
     */
    // public function forgot(Request $request)
    // {
    //     $request->validate([
    //         'email' => 'required|email|exists:users,email',
    //     ]);

    //     $status = Password::sendResetLink(
    //         $request->only('email')
    //     );

    //     if ($status === Password::RESET_LINK_SENT) {
    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Password reset link sent to your email'
    //         ]);
    //     }

    //     throw ValidationException::withMessages([
    //         'email' => [trans($status)],
    //     ]);
    // }

    public function forgot(Request $request)
    {
        Log::info('User logged in.');
        $request->validate(['email' => 'required|email|exists:users,email']);
        Log::info('User logged in.');
        $email = $request->email;
        $key = 'password-reset:' . $email;

        // Check if exceeded 5 attempts in 24 hours
        if (RateLimiter::tooManyAttempts($key, 50)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => "Too many attempts. Try again in " . ceil($seconds / 60) . " minutes.",
                'retry_after' => $seconds
            ], 429);
        }

        // Track attempt
        RateLimiter::hit($key, 86400); // 24 hours

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            RateLimiter::clear($key); // Clear on success
            return response()->json(['success' => true, 'message' => 'Reset link sent']);
        }

        return response()->json(['success' => false, 'message' => trans($status)], 400);
    }




    /**
     * Reset password
     */
    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = $password;
                $user->save();

                // Revoke all tokens
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset successful'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => trans($status)
        ], 400);
    }
}
