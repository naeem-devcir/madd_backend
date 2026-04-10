<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class CustomerAddressController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Customer address listing');
    }

    public function store(Request $request)
    {
        return $this->notImplemented('Customer address creation');
    }

    public function show(string $id)
    {
        return $this->notImplemented('Customer address details', ['id' => $id]);
    }

    public function update(Request $request, string $id)
    {
        return $this->notImplemented('Customer address update', ['id' => $id]);
    }

    public function destroy(string $id)
    {
        return $this->notImplemented('Customer address deletion', ['id' => $id]);
    }

    public function setDefault(string $id)
    {
        return $this->notImplemented('Customer default address selection', ['id' => $id]);
    }
}
