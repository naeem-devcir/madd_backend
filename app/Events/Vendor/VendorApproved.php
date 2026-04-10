<?php

namespace App\Events\Vendor;

use App\Models\Vendor\Vendor;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VendorApproved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $vendor;

    public function __construct(Vendor $vendor)
    {
        $this->vendor = $vendor;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('admin.vendors'),
            new PrivateChannel('vendor.' . $this->vendor->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'vendor.approved';
    }

    public function broadcastWith(): array
    {
        return [
            'vendor_id' => $this->vendor->id,
            'company_name' => $this->vendor->company_name,
            'status' => $this->vendor->status,
            'approved_at' => now()->toIso8601String(),
        ];
    }
}