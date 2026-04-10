<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

abstract class PlaceholderApiController extends Controller
{
    protected function notImplemented(string $feature, array $context = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $feature . ' is not implemented yet.',
            'context' => $context,
        ], 501);
    }
}
