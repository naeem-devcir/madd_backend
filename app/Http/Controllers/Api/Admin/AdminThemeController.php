<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class AdminThemeController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Admin theme listing');
    }

    public function store(Request $request)
    {
        return $this->notImplemented('Admin theme creation');
    }

    public function show(string $id)
    {
        return $this->notImplemented('Admin theme details', ['id' => $id]);
    }

    public function update(Request $request, string $id)
    {
        return $this->notImplemented('Admin theme update', ['id' => $id]);
    }

    public function destroy(string $id)
    {
        return $this->notImplemented('Admin theme deletion', ['id' => $id]);
    }

    public function setDefault(string $id)
    {
        return $this->notImplemented('Admin default theme selection', ['id' => $id]);
    }
}
