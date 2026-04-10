<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class VendorWebhookController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Vendor webhook listing');
    }

    public function store(Request $request)
    {
        return $this->notImplemented('Vendor webhook creation');
    }

    public function update(Request $request, string $id)
    {
        return $this->notImplemented('Vendor webhook update', ['id' => $id]);
    }

    public function destroy(string $id)
    {
        return $this->notImplemented('Vendor webhook deletion', ['id' => $id]);
    }

    public function test(string $id)
    {
        return $this->notImplemented('Vendor webhook test', ['id' => $id]);
    }
}
