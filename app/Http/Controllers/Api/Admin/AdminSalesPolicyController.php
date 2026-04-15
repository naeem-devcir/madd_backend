<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class AdminSalesPolicyController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Admin sales policy listing');
    }

    public function store(Request $request)
    {
        return $this->notImplemented('Admin sales policy creation');
    }

    public function show(string $id)
    {
        return $this->notImplemented('Admin sales policy details', ['id' => $id]);
    }

    public function update(Request $request, string $id)
    {
        return $this->notImplemented('Admin sales policy update', ['id' => $id]);
    }

    public function destroy(string $id)
    {
        return $this->notImplemented('Admin sales policy deletion', ['id' => $id]);
    }
}

