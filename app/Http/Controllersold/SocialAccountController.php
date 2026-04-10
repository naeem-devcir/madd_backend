<?php
// app/Http/Controllers/SocialAccountController.php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class SocialAccountController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! auth()->check()) {
            return response()->json([
                'message' => 'Authentication is required to link a social account.'
            ], 401);
        }

        // ✅ Validate ONLY provider data
        $validated = $request->validate([
            'provider' => 'required|string|max:50',
            'provider_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('social_accounts')->where(fn ($query) => $query
                    ->where('provider', $request->provider)),
            ],
            'provider_email' => 'nullable|email',
            'access_token' => 'nullable|string',
            'refresh_token' => 'nullable|string',
            'expires_at' => 'nullable|date',
        ]);

        // ✅ Force authenticated user ID
        $validated['user_id'] = auth()->id();

        $socialAccount = SocialAccount::create($validated);

        return response()->json([
            'message' => 'Social account linked successfully.',
            'data' => $socialAccount
        ], 201);
    }
}
