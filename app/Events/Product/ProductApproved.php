<?php

namespace App\Events\Product;

use App\Models\Product\ProductDraft;
use App\Models\Product\VendorProduct;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductApproved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $product;
    public $draft;

    public function __construct(VendorProduct $product, ProductDraft $draft)
    {
        $this->product = $product;
        $this->draft = $draft;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('vendor.' . $this->product->vendor->user->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'product.approved';
    }

    public function broadcastWith(): array
    {
        return [
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'status' => $this->product->status,
            'approved_at' => now()->toIso8601String(),
        ];
    }
}