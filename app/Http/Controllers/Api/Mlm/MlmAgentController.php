<?php

namespace App\Http\Controllers\Api\Mlm;

use App\Http\Controllers\Api\PlaceholderApiController;

class MlmAgentController extends PlaceholderApiController
{
    public function dashboard()
    {
        return $this->notImplemented('MLM dashboard');
    }

    public function team()
    {
        return $this->notImplemented('MLM team listing');
    }

    public function teamMember(string $id)
    {
        return $this->notImplemented('MLM team member details', ['id' => $id]);
    }

    public function commissions()
    {
        return $this->notImplemented('MLM commissions');
    }

    public function statistics()
    {
        return $this->notImplemented('MLM statistics');
    }

    public function inviteVendor()
    {
        return $this->notImplemented('MLM vendor invitation');
    }

    public function invitations()
    {
        return $this->notImplemented('MLM invitation listing');
    }

    public function structure()
    {
        return $this->notImplemented('MLM structure');
    }
}
