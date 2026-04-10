<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\PlaceholderApiController;

class VendorReturnController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Vendor returns listing');
    }

    public function show(string $id)
    {
        return $this->notImplemented('Vendor return details', ['id' => $id]);
    }

    public function approve(string $id)
    {
        return $this->notImplemented('Vendor return approval', ['id' => $id]);
    }

    public function reject(string $id)
    {
        return $this->notImplemented('Vendor return rejection', ['id' => $id]);
    }

    public function markAsReceived(string $id)
    {
        return $this->notImplemented('Vendor return receipt confirmation', ['id' => $id]);
    }

    public function processRefund(string $id)
    {
        return $this->notImplemented('Vendor return refund', ['id' => $id]);
    }
}
