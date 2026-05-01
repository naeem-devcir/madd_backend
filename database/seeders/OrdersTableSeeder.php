<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class OrdersTableSeeder extends Seeder
{
    public function run(): void
    {
        // Define allowed values
        $allowedCustomerIds = [2, 3, 8];
        $allowedClaimedByUserIds = [2, 3, 8];
        $allowedVendors = [1, 2];
        $allowedCarrierIds = [1, 2, 3, 4, 5, 6];
        $vendorStoreId = 2; // Make sure this exists in vendor_stores table
        
        $statuses = ['pending', 'processing', 'completed', 'cancelled', 'on_hold'];
        $paymentStatuses = ['pending', 'paid', 'refunded', 'chargeback', 'failed'];
        $fulfillmentStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        $sources = ['web', 'mobile', 'marketplace', 'erp', 'pos'];
        $syncStatuses = ['pending', 'synced', 'failed'];
        $currencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD'];
        $paymentMethods = ['credit_card', 'paypal', 'bank_transfer', 'cash_on_delivery', 'crypto', 'store_credit'];
        $shippingMethods = ['standard', 'express', 'overnight', 'pickup', 'international'];
        $countries = ['US', 'GB', 'CA', 'AU', 'DE', 'FR', 'IT', 'ES', 'MX', 'BR'];
        
        // FIRST PASS: Insert all orders with parent_order_id = null
        $orders = [];
        $tempIds = [];
        
        for ($i = 1; $i <= 50; $i++) {
            $customerId = $allowedCustomerIds[array_rand($allowedCustomerIds)];
            $vendorId = $allowedVendors[array_rand($allowedVendors)];
            $carrierId = $allowedCarrierIds[array_rand($allowedCarrierIds)];
            
            // No parent IDs in first pass
            $parentOrderId = null;
            
            $claimedByUserId = null;
            if (rand(0, 1)) {
                $claimedByUserId = $allowedClaimedByUserIds[array_rand($allowedClaimedByUserIds)];
            }
            
            $status = $statuses[array_rand($statuses)];
            $paymentStatus = $paymentStatuses[array_rand($paymentStatuses)];
            $fulfillmentStatus = $fulfillmentStatuses[array_rand($fulfillmentStatuses)];
            
            $subtotal = rand(1500, 50000) / 100;
            $taxRate = rand(500, 2500) / 100;
            $taxAmount = round($subtotal * ($taxRate / 100), 2);
            $shippingAmount = rand(0, 2500) / 100;
            $discountAmount = rand(0, 2000) / 100;
            $grandTotal = round($subtotal + $taxAmount + $shippingAmount - $discountAmount, 2);
            
            $commissionRate = rand(800, 2000) / 100;
            $commissionAmount = round($grandTotal * ($commissionRate / 100), 2);
            $vendorPayoutAmount = round($grandTotal - $commissionAmount, 2);
            
            $createdAt = Carbon::now()->subDays(rand(0, 60))->subHours(rand(0, 23));
            $updatedAt = $createdAt->copy()->addHours(rand(0, 72));
            $claimedAt = $claimedByUserId && rand(0, 1) ? $createdAt->copy()->addMinutes(rand(5, 120)) : null;
            
            $shippedAt = null;
            $deliveredAt = null;
            $settledAt = null;
            $deletedAt = null;
            
            if ($fulfillmentStatus == 'shipped') {
                $shippedAt = $createdAt->copy()->addDays(rand(1, 7));
            } elseif ($fulfillmentStatus == 'delivered') {
                $shippedAt = $createdAt->copy()->addDays(rand(1, 5));
                $deliveredAt = $shippedAt->copy()->addDays(rand(1, 5));
            }
            
            if ($paymentStatus == 'paid') {
                $settledAt = $createdAt->copy()->addDays(rand(7, 21));
            }
            
            if ($status == 'cancelled' && rand(0, 1)) {
                $deletedAt = $createdAt->copy()->addDays(rand(1, 7));
            }
            
            $trackingNumber = null;
            if ($shippedAt) {
                $trackingNumber = $this->generateTrackingNumber($carrierId);
            }
            
            $guestToken = rand(1, 100) <= 20 ? Str::random(32) : null;
            $couponCode = rand(1, 100) <= 30 ? 'SAVE' . rand(10, 30) : null;
            $couponId = $couponCode ? rand(1, 20) : null;
            
            $order = [
                'uuid' => Str::uuid(),
                'magento_order_id' => rand(100000, 999999),
                'magento_order_increment_id' => 'ORD-' . str_pad($i, 8, '0', STR_PAD_LEFT),
                'parent_order_id' => $parentOrderId, // NULL for now
                'vendor_id' => $vendorId,
                'vendor_store_id' => $vendorStoreId,
                'customer_id' => $customerId,
                'claimed_by_user_id' => $claimedByUserId,
                'customer_email' => 'customer' . $customerId . '@example.com',
                'customer_firstname' => $this->getFirstNameByCustomerId($customerId),
                'customer_lastname' => $this->getLastNameByCustomerId($customerId),
                'customer_ip' => $this->generateRandomIP(),
                'guest_token' => $guestToken,
                'claimed_at' => $claimedAt,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'fulfillment_status' => $fulfillmentStatus,
                'currency_code' => $currencies[array_rand($currencies)],
                'currency_rate' => round(rand(70, 150) / 100, 4),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'tax_rate' => $taxRate,
                'shipping_amount' => $shippingAmount,
                'discount_amount' => $discountAmount,
                'grand_total' => $grandTotal,
                'country_code' => $countries[array_rand($countries)],
                'commission_amount' => $commissionAmount,
                'commission_rate' => $commissionRate,
                'vendor_payout_amount' => $vendorPayoutAmount,
                'payment_method' => $paymentMethods[array_rand($paymentMethods)],
                'payment_fee' => round(rand(0, 500) / 100, 2),
                'shipping_method' => $shippingMethods[array_rand($shippingMethods)],
                'carrier_id' => $carrierId,
                'tracking_number' => $trackingNumber,
                'coupon_code' => $couponCode,
                'coupon_id' => $couponId,
                'source' => $sources[array_rand($sources)],
                'shipping_address' => json_encode($this->getShippingAddress()),
                'billing_address' => json_encode($this->getBillingAddress()),
                'customer_note' => rand(0, 1) ? $this->getRandomCustomerNote() : null,
                'admin_note' => rand(0, 1) ? $this->getRandomAdminNote() : null,
                'shipped_at' => $shippedAt,
                'delivered_at' => $deliveredAt,
                'settled_at' => $settledAt,
                'settlement_id' => $settledAt ? rand(1000, 9999) : null,
                'synced_at' => Carbon::now()->subMinutes(rand(0, 1440)),
                'sync_status' => $syncStatuses[array_rand($syncStatuses)],
                'metadata' => json_encode([
                    'user_agent' => $this->getRandomUserAgent(),
                    'device_type' => rand(0, 1) ? 'mobile' : 'desktop',
                    'order_source' => 'seeder',
                ]),
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
                'deleted_at' => $deletedAt,
            ];
            
            $orders[] = $order;
            $tempIds[] = $i; // Store temporary ID for reference
        }
        
        // Insert all orders first
        foreach (array_chunk($orders, 25) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        
        // SECOND PASS: Update some orders to have parent_order_id
        // Get all actual order IDs from database
        $actualOrderIds = DB::table('orders')->pluck('id')->toArray();
        
        if (!empty($actualOrderIds)) {
            // Randomly select some orders to become parent orders
            $parentOrders = array_rand(array_flip($actualOrderIds), min(10, count($actualOrderIds)));
            
            // Update random orders to have parent_order_id
            foreach ($actualOrderIds as $orderId) {
                // 30% chance to give this order a parent
                if (rand(1, 100) <= 30) {
                    $randomParentId = $parentOrders[array_rand($parentOrders)];
                    // Make sure parent is not itself
                    if ($randomParentId != $orderId) {
                        DB::table('orders')
                            ->where('id', $orderId)
                            ->update(['parent_order_id' => $randomParentId]);
                    }
                }
            }
        }
        
        $this->command->info('50 orders seeded successfully with proper parent-child relationships!');
    }
    
    // Helper methods (keep same as before)
    private function getFirstNameByCustomerId($customerId): string
    {
        $names = [2 => 'John', 3 => 'Sarah', 8 => 'Michael'];
        return $names[$customerId] ?? 'Customer';
    }
    
    private function getLastNameByCustomerId($customerId): string
    {
        $names = [2 => 'Smith', 3 => 'Johnson', 8 => 'Williams'];
        return $names[$customerId] ?? 'User';
    }
    
    private function generateRandomIP(): string
    {
        return rand(1, 255) . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 255);
    }
    
    private function generateTrackingNumber($carrierId): string
    {
        $prefixes = [1 => 'DHL', 2 => 'FDX', 3 => 'UPS', 4 => 'USPS', 5 => 'CP', 6 => 'ARAMEX'];
        $prefix = $prefixes[$carrierId] ?? 'CR';
        return $prefix . '-' . strtoupper(Str::random(10));
    }
    
    private function getShippingAddress(): array
    {
        $addresses = [
            ['street' => '123 Main Street', 'city' => 'New York', 'state' => 'NY', 'zipcode' => '10001', 'country' => 'US', 'phone' => '+1 (212) 555-1234', 'firstname' => 'John', 'lastname' => 'Doe'],
            ['street' => '456 Oak Avenue', 'city' => 'Los Angeles', 'state' => 'CA', 'zipcode' => '90001', 'country' => 'US', 'phone' => '+1 (310) 555-5678', 'firstname' => 'Sarah', 'lastname' => 'Smith'],
            ['street' => '789 Maple Drive', 'city' => 'Chicago', 'state' => 'IL', 'zipcode' => '60601', 'country' => 'US', 'phone' => '+1 (312) 555-9012', 'firstname' => 'Michael', 'lastname' => 'Johnson'],
            ['street' => '321 Queen Street', 'city' => 'Toronto', 'state' => 'ON', 'zipcode' => 'M5V 2A1', 'country' => 'CA', 'phone' => '+1 (416) 555-3456', 'firstname' => 'Emily', 'lastname' => 'Brown'],
            ['street' => '567 King\'s Road', 'city' => 'London', 'state' => 'England', 'zipcode' => 'SW1A 1AA', 'country' => 'GB', 'phone' => '+44 20 7946 0123', 'firstname' => 'David', 'lastname' => 'Wilson'],
        ];
        return $addresses[array_rand($addresses)];
    }
    
    private function getBillingAddress(): array
    {
        return $this->getShippingAddress();
    }
    
    private function getRandomCustomerNote(): string
    {
        $notes = ['Please leave package at the back door', 'Call before delivery', 'Handle with care - fragile items', 'Gift wrapping required', 'Deliver only between 9 AM - 5 PM'];
        return $notes[array_rand($notes)];
    }
    
    private function getRandomAdminNote(): string
    {
        $notes = ['Priority shipping - customer paid extra', 'Verify address before shipping', 'High value order - require signature', 'Customer called about expedited delivery', 'Flag for fraud check', 'Return customer - VIP status'];
        return $notes[array_rand($notes)];
    }
    
    private function getRandomUserAgent(): string
    {
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15',
            'Mozilla/5.0 (Linux; Android 10; SM-G973F) AppleWebKit/537.36'
        ];
        return $userAgents[array_rand($userAgents)];
    }
}