<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class InventoryIntegrationController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Inventory integration listing');
    }

    public function batchUpdate(Request $request)
    {
        return $this->notImplemented('Inventory integration batch update');
    }
}

