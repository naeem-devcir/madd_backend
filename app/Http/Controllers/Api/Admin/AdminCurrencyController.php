<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class AdminCurrencyController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Admin currency listing');
    }

    public function store(Request $request)
    {
        return $this->notImplemented('Admin currency creation');
    }

    public function show(string $id)
    {
        return $this->notImplemented('Admin currency details', ['id' => $id]);
    }

    public function update(Request $request, string $id)
    {
        return $this->notImplemented('Admin currency update', ['id' => $id]);
    }

    public function destroy(string $id)
    {
        return $this->notImplemented('Admin currency deletion', ['id' => $id]);
    }

    public function updateExchangeRate(string $code)
    {
        return $this->notImplemented('Admin exchange rate update', ['code' => $code]);
    }
}

