<?php

namespace App\Models\Order;

use App\Models\Config\Courier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderTracking extends Model
{
    use HasFactory;

    protected $table = 'order_tracking';

    protected $fillable = [
        'order_id',
        'carrier_id',
        'tracking_number',
        'tracking_url',
        'label_url',
        'status',
        'estimated_delivery',
        'delivered_at',
        'last_update',
        'tracking_events',
    ];

    protected $casts = [
        'estimated_delivery' => 'date',
        'delivered_at' => 'datetime',
        'last_update' => 'datetime',
        'tracking_events' => 'array',
    ];

    // ========== Relationships ==========
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'uuid');
    }
    
    public function carrier()
    {
        return $this->belongsTo(Courier::class, 'carrier_id', 'uuid');
    }
    
    // ========== Accessors ==========
    
    public function getTrackingUrlAttribute($value): ?string
    {
        if ($value) {
            return $value;
        }
        
        // Generate tracking URL from carrier template
        if ($this->carrier && $this->carrier->tracking_url_template) {
            return str_replace('{tracking_number}', $this->tracking_number, $this->carrier->tracking_url_template);
        }
        
        return null;
    }
    
    public function getIsDeliveredAttribute(): bool
    {
        return !is_null($this->delivered_at);
    }
    
    // ========== Methods ==========
    
    public function addTrackingEvent(string $status, string $location, ?string $description = null): void
    {
        $events = $this->tracking_events ?? [];
        
        $events[] = [
            'status' => $status,
            'location' => $location,
            'description' => $description,
            'timestamp' => now()->toIso8601String(),
        ];
        
        $this->tracking_events = $events;
        $this->last_update = now();
        $this->status = $status;
        $this->save();
        
        // Update order status if delivered
        if ($status === 'delivered' && !$this->order->is_delivered) {
            $this->delivered_at = now();
            $this->save();
            $this->order->markAsDelivered();
        }
    }
    
    public function updateFromCarrier(array $data): void
    {
        $this->status = $data['status'] ?? $this->status;
        $this->last_update = now();
        
        if (isset($data['events'])) {
            $this->tracking_events = array_merge($this->tracking_events ?? [], $data['events']);
        }
        
        if (isset($data['estimated_delivery'])) {
            $this->estimated_delivery = $data['estimated_delivery'];
        }
        
        if (isset($data['delivered']) && $data['delivered'] === true) {
            $this->delivered_at = now();
            $this->order->markAsDelivered();
        }
        
        $this->save();
    }
}
