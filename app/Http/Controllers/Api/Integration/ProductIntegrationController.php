<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class ProductIntegrationController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Product integration listing');
    }

    public function show(string $sku)
    {
        return $this->notImplemented('Product integration details', ['sku' => $sku]);
    }

    public function updateInventory(Request $request, string $sku)
    {
        return $this->notImplemented('Product integration inventory update', ['sku' => $sku]);
    }

    public function updatePrice(Request $request, string $sku)
    {
        return $this->notImplemented('Product integration price update', ['sku' => $sku]);
    }
}
