<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class AdminCourierController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Admin courier listing');
    }

    public function store(Request $request)
    {
        return $this->notImplemented('Admin courier creation');
    }

    public function show(string $id)
    {
        return $this->notImplemented('Admin courier details', ['id' => $id]);
    }

    public function update(Request $request, string $id)
    {
        return $this->notImplemented('Admin courier update', ['id' => $id]);
    }

    public function destroy(string $id)
    {
        return $this->notImplemented('Admin courier deletion', ['id' => $id]);
    }

    public function testConnection(string $id)
    {
        return $this->notImplemented('Admin courier connection test', ['id' => $id]);
    }
}

