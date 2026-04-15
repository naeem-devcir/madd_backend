<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class VendorApiKeyController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Vendor API key listing');
    }

    public function store(Request $request)
    {
        return $this->notImplemented('Vendor API key creation');
    }

    public function destroy(string $id)
    {
        return $this->notImplemented('Vendor API key deletion', ['id' => $id]);
    }

    public function regenerate(string $id)
    {
        return $this->notImplemented('Vendor API key regeneration', ['id' => $id]);
    }
}

