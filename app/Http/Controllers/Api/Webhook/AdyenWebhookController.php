<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Http\Request;

class AdyenWebhookController extends PlaceholderApiController
{
    public function handle(Request $request)
    {
        return $this->notImplemented('Adyen webhook handling');
    }
}
