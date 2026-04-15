<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class AdminPlanController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Admin plan listing');
    }

    public function store(Request $request)
    {
        return $this->notImplemented('Admin plan creation');
    }

    public function show(string $id)
    {
        return $this->notImplemented('Admin plan details', ['id' => $id]);
    }

    public function update(Request $request, string $id)
    {
        return $this->notImplemented('Admin plan update', ['id' => $id]);
    }

    public function destroy(string $id)
    {
        return $this->notImplemented('Admin plan deletion', ['id' => $id]);
    }

    public function setDefault(string $id)
    {
        return $this->notImplemented('Admin default plan selection', ['id' => $id]);
    }
}

