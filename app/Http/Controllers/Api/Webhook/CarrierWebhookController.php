<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Api\PlaceholderApiController;

class CarrierWebhookController extends PlaceholderApiController
{
    public function handleDHL()
    {
        return $this->notImplemented('DHL webhook handling');
    }

    public function handleUPS()
    {
        return $this->notImplemented('UPS webhook handling');
    }

    public function handleFedEx()
    {
        return $this->notImplemented('FedEx webhook handling');
    }

    public function handleDPD()
    {
        return $this->notImplemented('DPD webhook handling');
    }
}
