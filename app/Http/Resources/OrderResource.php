<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the order resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'order_number' => $this->order_number,
            'magento_order_id' => $this->magento_order_id,
            'magento_order_increment_id' => $this->magento_order_increment_id,
            
            // Status
            'status' => $this->status,
            'status_label' => $this->getStatusLabelAttribute(),
            'status_color' => $this->getStatusColorAttribute(),
            'payment_status' => $this->payment_status,
            'fulfillment_status' => $this->fulfillment_status,
            
            // Customer Info
            'customer' => $this->whenLoaded('customer', function() {
                return [
                    'id' => $this->customer->uuid,
                    'name' => $this->customer->full_name,
                    'email' => $this->customer->email,
                    'avatar' => $this->customer->avatar_url,
                ];
            }),
            'customer_name' => $this->customer_firstname . ' ' . $this->customer_lastname,
            'customer_email' => $this->customer_email,
            'is_guest' => $this->is_guest_order,
            
            // Vendor Info
            'vendor' => $this->whenLoaded('vendor', function() {
                return [
                    'id' => $this->vendor->uuid,
                    'name' => $this->vendor->company_name,
                    'slug' => $this->vendor->company_slug,
                ];
            }),
            'store' => $this->whenLoaded('vendorStore', function() {
                return new VendorStoreResource($this->vendorStore);
            }),
            
            // Financials
            'currency' => $this->currency_code,
            'subtotal' => $this->subtotal,
            'subtotal_formatted' => $this->formatted_subtotal,
            'tax_amount' => $this->tax_amount,
            'tax_rate' => $this->tax_rate,
            'shipping_amount' => $this->shipping_amount,
            'discount_amount' => $this->discount_amount,
            'grand_total' => $this->grand_total,
            'grand_total_formatted' => $this->formatted_grand_total,
            
            // Commission & Payout
            'commission_amount' => $this->commission_amount,
            'commission_rate' => $this->commission_rate,
            'vendor_payout_amount' => $this->vendor_payout_amount,
            
            // Payment & Shipping
            'payment_method' => $this->payment_method,
            'payment_fee' => $this->payment_fee,
            'shipping_method' => $this->shipping_method,
            
            // Tracking
            'tracking' => $this->whenLoaded('tracking', function() {
                return [
                    'number' => $this->tracking->tracking_number,
                    'url' => $this->tracking->tracking_url,
                    'carrier' => $this->tracking->carrier?->name,
                    'status' => $this->tracking->status,
                    'estimated_delivery' => $this->tracking->estimated_delivery?->toDateString(),
                    'events' => $this->tracking->tracking_events,
                ];
            }),
            'carrier' => $this->whenLoaded('carrier', function() {
                return [
                    'id' => $this->carrier->id,
                    'name' => $this->carrier->name,
                    'code' => $this->carrier->code,
                ];
            }),
            'tracking_number' => $this->tracking_number,
            
            // Coupon
            'coupon' => $this->whenLoaded('coupon', function() {
                return [
                    'code' => $this->coupon->code,
                    'discount_type' => $this->coupon->discount_type,
                    'discount_value' => $this->coupon->discount_value,
                ];
            }),
            'coupon_code' => $this->coupon_code,
            
            // Addresses
            'shipping_address' => $this->shipping_address,
            'shipping_address_formatted' => $this->shipping_address_formatted,
            'billing_address' => $this->billing_address,
            
            // Notes
            'customer_note' => $this->customer_note,
            'admin_note' => $this->admin_note,
            
            // Items
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            
            // Timeline
            'timeline' => $this->when($request->include_timeline ?? false, function() {
                return $this->getTimeline();
            }),
            'status_history' => OrderStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
            
            // Payment Transactions
            'payment_transactions' => $this->whenLoaded('paymentTransactions', function() {
                return $this->paymentTransactions->map(function($transaction) {
                    return [
                        'id' => $transaction->id,
                        'gateway' => $transaction->gateway,
                        'transaction_id' => $transaction->gateway_transaction_id,
                        'amount' => $transaction->amount,
                        'status' => $transaction->status,
                        'captured_at' => $transaction->captured_at?->toIso8601String(),
                    ];
                });
            }),
            
            // Settlement
            'settlement' => $this->whenLoaded('settlement', function() {
                return [
                    'id' => $this->settlement->id,
                    'settlement_number' => $this->settlement->settlement_number,
                    'status' => $this->settlement->status,
                    'paid_at' => $this->settlement->paid_at?->toIso8601String(),
                ];
            }),
            'is_settled' => $this->is_settled,
            'settled_at' => $this->settled_at?->toIso8601String(),
            
            // Dates
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            
            // Flags
            'is_paid' => $this->is_paid,
            'is_refunded' => $this->is_refunded,
            'is_shipped' => $this->is_shipped,
            'is_delivered' => $this->is_delivered,
            'can_be_cancelled' => $this->canBeCancelled(),
            'can_be_refunded' => $this->canBeRefunded(),
            
            // Source
            'source' => $this->source,
            
            // Metadata
            'metadata' => $this->metadata,
        ];
    }
    
    /**
     * Get status label
     */
    private function getStatusLabelAttribute(): string
    {
        $labels = [
            'pending' => 'Pending',
            'processing' => 'Processing',
            'shipped' => 'Shipped',
            'delivered' => 'Delivered',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
        ];
        
        return $labels[$this->status] ?? ucfirst($this->status);
    }
    
    /**
     * Get status color
     */
    private function getStatusColorAttribute(): string
    {
        $colors = [
            'pending' => 'warning',
            'processing' => 'info',
            'shipped' => 'primary',
            'delivered' => 'success',
            'completed' => 'success',
            'cancelled' => 'danger',
            'refunded' => 'secondary',
        ];
        
        return $colors[$this->status] ?? 'secondary';
    }
    
    /**
     * Get formatted subtotal
     */
    private function getFormattedSubtotalAttribute(): string
    {
        return $this->currency_code . ' ' . number_format($this->subtotal, 2);
    }
    
    /**
     * Get formatted grand total
     */
    private function getFormattedGrandTotalAttribute(): string
    {
        return $this->currency_code . ' ' . number_format($this->grand_total, 2);
    }
    
    /**
     * Get order timeline
     */
    private function getTimeline(): array
    {
        $timeline = [];
        
        // Order created
        $timeline[] = [
            'event' => 'Order Created',
            'status' => 'pending',
            'description' => 'Order has been placed',
            'timestamp' => $this->created_at?->toIso8601String(),
        ];
        
        // Payment received
        if ($this->payment_status === 'paid') {
            $timeline[] = [
                'event' => 'Payment Received',
                'status' => 'paid',
                'description' => 'Payment has been confirmed',
                'timestamp' => $this->updated_at?->toIso8601String(),
            ];
        }
        
        // Order shipped
        if ($this->shipped_at) {
            $timeline[] = [
                'event' => 'Order Shipped',
                'status' => 'shipped',
                'description' => 'Order has been shipped' . ($this->tracking_number ? ' - Tracking: ' . $this->tracking_number : ''),
                'timestamp' => $this->shipped_at->toIso8601String(),
            ];
        }
        
        // Order delivered
        if ($this->delivered_at) {
            $timeline[] = [
                'event' => 'Order Delivered',
                'status' => 'delivered',
                'description' => 'Order has been delivered',
                'timestamp' => $this->delivered_at->toIso8601String(),
            ];
        }
        
        return $timeline;
    }
}