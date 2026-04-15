<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class VendorTeamController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Vendor team listing');
    }

    public function invite(Request $request)
    {
        return $this->notImplemented('Vendor team invitation');
    }

    public function remove(string $userId)
    {
        return $this->notImplemented('Vendor team member removal', ['user_id' => $userId]);
    }

    public function updateRole(Request $request, string $userId)
    {
        return $this->notImplemented('Vendor team role update', ['user_id' => $userId]);
    }

    public function resendInvitation(string $invitationId)
    {
        return $this->notImplemented('Vendor invitation resend', ['invitation_id' => $invitationId]);
    }
}

