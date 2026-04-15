<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\PlaceholderApiController;

class CustomerPaymentController extends PlaceholderApiController
{
    public function index()
    {
        return $this->notImplemented('Customer payment methods');
    }

    public function destroy(string $id)
    {
        return $this->notImplemented('Customer payment method deletion', ['id' => $id]);
    }
}

