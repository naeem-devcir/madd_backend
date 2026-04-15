<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class AdminLanguageController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Admin language listing');
    }

    public function store(Request $request)
    {
        return $this->notImplemented('Admin language creation');
    }

    public function show(string $id)
    {
        return $this->notImplemented('Admin language details', ['id' => $id]);
    }

    public function update(Request $request, string $id)
    {
        return $this->notImplemented('Admin language update', ['id' => $id]);
    }

    public function destroy(string $id)
    {
        return $this->notImplemented('Admin language deletion', ['id' => $id]);
    }
}

