<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class AdminCountryController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Admin country listing');
    }

    public function store(Request $request)
    {
        return $this->notImplemented('Admin country creation');
    }

    public function show(string $id)
    {
        return $this->notImplemented('Admin country details', ['id' => $id]);
    }

    public function update(Request $request, string $id)
    {
        return $this->notImplemented('Admin country update', ['id' => $id]);
    }

    public function destroy(string $id)
    {
        return $this->notImplemented('Admin country deletion', ['id' => $id]);
    }

    public function activate(string $code)
    {
        return $this->notImplemented('Admin country activation', ['code' => $code]);
    }
}

