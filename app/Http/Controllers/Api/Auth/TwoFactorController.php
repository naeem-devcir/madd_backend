<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class TwoFactorController extends PlaceholderApiController
{
    public function enable(Request $request)
    {
        return $this->notImplemented('Two-factor enable');
    }

    public function verify(Request $request)
    {
        return $this->notImplemented('Two-factor verify');
    }

    public function disable(Request $request)
    {
        return $this->notImplemented('Two-factor disable');
    }

    public function recoveryCodes()
    {
        return $this->notImplemented('Two-factor recovery codes');
    }
}

