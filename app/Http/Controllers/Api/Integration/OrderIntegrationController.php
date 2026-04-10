<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class OrderIntegrationController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Order integration listing');
    }

    public function show(string $id)
    {
        return $this->notImplemented('Order integration details', ['id' => $id]);
    }

    public function updateStatus(Request $request, string $id)
    {
        return $this->notImplemented('Order integration status update', ['id' => $id]);
    }

    public function createShipment(Request $request, string $id)
    {
        return $this->notImplemented('Order integration shipment creation', ['id' => $id]);
    }
}
