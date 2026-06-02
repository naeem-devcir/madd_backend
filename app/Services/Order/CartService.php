<?php

namespace App\Services\Order;

use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Services\Integration\MagentoService;
use Illuminate\Support\Facades\Log;

class CartService
{
    /**
     * Create a temporary cart for a customer
     * 
     * @param Vendor $vendor
     * @param int $customerId
     * @return string|null Cart ID
     */
    public function createTemporaryCart(Vendor $vendor, int $customerId): ?string
    {
        try {
            $magentoService = MagentoService::forVendor($vendor);
            
            // Create cart for customer
            $response = $magentoService->post("customers/{$customerId}/carts");
            
            $cartId = $response['value'] ?? $response['id'] ?? $response['cart_id'] ?? null;
            
            if (!$cartId && is_numeric($response[0] ?? null)) {
                $cartId = $response[0];
            }
            
            return $cartId;
        } catch (\Exception $e) {
            Log::error('Failed to create temporary cart', [
                'vendor_id' => $vendor->id,
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Get available payment methods for a customer
     * 
     * @param Vendor $vendor
     * @param VendorStore $store
     * @param int $customerId
     * @return array
     */
    public function getPaymentMethods(Vendor $vendor, VendorStore $store, int $customerId): array
    {
        try {
            $magentoService = MagentoService::forVendor($vendor);
            
            // Create temporary cart
            $cartId = $this->createTemporaryCart($vendor, $customerId);
            
            if (!$cartId) {
                throw new \Exception('Failed to create temporary cart');
            }
            
            // Get payment methods
            $paymentMethods = $magentoService->get("carts/{$cartId}/payment-methods");
            
            // Format payment methods for frontend
            $formattedMethods = array_map(function ($method) {
                return [
                    'code' => $method['code'] ?? '',
                    'title' => $method['title'] ?? ucfirst(str_replace('_', ' ', $method['code'] ?? '')),
                    'is_default' => false,
                    'sort_order' => $method['sort_order'] ?? 0,
                ];
            }, $paymentMethods ?? []);
            
            // Sort by sort_order
            usort($formattedMethods, function ($a, $b) {
                return ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0);
            });
            
            return $formattedMethods;
            
        } catch (\Exception $e) {
            Log::error('Failed to get payment methods', [
                'vendor_id' => $vendor->id,
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            
            // Return default fallback methods if Magento fails
            return $this->getDefaultPaymentMethods();
        }
    }
    
    /**
     * Get available shipping methods for a customer with address
     * 
     * @param Vendor $vendor
     * @param VendorStore $store
     * @param int $customerId
     * @param array $shippingAddress
     * @return array
     */
    public function getShippingMethods(Vendor $vendor, VendorStore $store, int $customerId, array $shippingAddress): array
    {
        try {
            $magentoService = MagentoService::forVendor($vendor);
            
            // Create temporary cart
            $cartId = $this->createTemporaryCart($vendor, $customerId);
            
            if (!$cartId) {
                throw new \Exception('Failed to create temporary cart');
            }
            
            // Add a dummy product to cart (required for shipping estimation)
            // We'll add a simple product with minimal impact
            $this->addDummyProductToCart($magentoService, $cartId);
            
            // Format shipping address for Magento
            $formattedAddress = $this->formatShippingAddress($shippingAddress);
            
            // Get shipping methods
            $shippingMethods = $magentoService->post("carts/{$cartId}/estimate-shipping-methods", [
                'address' => $formattedAddress
            ]);
            
            // Format shipping methods for frontend
            $formattedMethods = array_map(function ($method) {
                return [
                    'carrier_code' => $method['carrier_code'] ?? '',
                    'method_code' => $method['method_code'] ?? '',
                    'carrier_title' => $method['carrier_title'] ?? '',
                    'method_title' => $method['method_title'] ?? '',
                    'amount' => (float) ($method['amount'] ?? 0),
                    'base_amount' => (float) ($method['base_amount'] ?? 0),
                    'price_incl_tax' => (float) ($method['price_incl_tax'] ?? 0),
                    'error_message' => $method['error_message'] ?? null,
                ];
            }, $shippingMethods ?? []);
            
            // Remove the dummy product (optional - cart will be temporary anyway)
            
            return $formattedMethods;
            
        } catch (\Exception $e) {
            Log::error('Failed to get shipping methods', [
                'vendor_id' => $vendor->id,
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            
            // Return default fallback methods if Magento fails
            return $this->getDefaultShippingMethods();
        }
    }
    
    /**
     * Add a dummy product to cart for shipping estimation
     */
    private function addDummyProductToCart($magentoService, string $cartId): void
    {
        try {
            // Try to find a simple product to add
            // This is a minimal product that won't affect calculations much
            $response = $magentoService->get('products', [
                'searchCriteria[pageSize]' => 1,
                'searchCriteria[filterGroups][0][filters][0][field]' => 'type_id',
                'searchCriteria[filterGroups][0][filters][0][value]' => 'simple',
                'searchCriteria[filterGroups][0][filters][0][conditionType]' => 'eq',
            ]);
            
            $products = $response['items'] ?? [];
            
            if (!empty($products)) {
                $product = $products[0];
                $magentoService->post("carts/{$cartId}/items", [
                    'cartItem' => [
                        'sku' => $product['sku'],
                        'qty' => 1,
                        'quote_id' => $cartId,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            // Silently fail - shipping estimation will still work without products
            Log::warning('Failed to add dummy product for shipping estimation', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Format shipping address for Magento API
     */
    private function formatShippingAddress(array $address): array
    {
        return [
            'country_id' => $address['country_id'] ?? 'US',
            'region' => $address['region'] ?? '',
            'region_id' => $address['region_id'] ?? 0,
            'city' => $address['city'] ?? '',
            'postcode' => $address['postcode'] ?? '',
            'street' => [$address['street'] ?? ''],
            'firstname' => $address['firstname'] ?? 'Guest',
            'lastname' => $address['lastname'] ?? 'User',
            'telephone' => $address['telephone'] ?? '0000000000',
        ];
    }
    
    /**
     * Get default payment methods (fallback)
     */
    private function getDefaultPaymentMethods(): array
    {
        return [
            ['code' => 'checkmo', 'title' => 'Check / Money Order', 'is_default' => true, 'sort_order' => 10],
            ['code' => 'banktransfer', 'title' => 'Bank Transfer', 'is_default' => false, 'sort_order' => 20],
            ['code' => 'cashondelivery', 'title' => 'Cash On Delivery', 'is_default' => false, 'sort_order' => 30],
        ];
    }
    
    /**
     * Get default shipping methods (fallback)
     */
    private function getDefaultShippingMethods(): array
    {
        return [
            [
                'carrier_code' => 'flatrate',
                'method_code' => 'flatrate',
                'carrier_title' => 'Flat Rate',
                'method_title' => 'Fixed',
                'amount' => 10.00,
                'price_incl_tax' => 10.00,
            ],
            [
                'carrier_code' => 'freeshipping',
                'method_code' => 'freeshipping',
                'carrier_title' => 'Free Shipping',
                'method_title' => 'Free',
                'amount' => 0.00,
                'price_incl_tax' => 0.00,
            ],
        ];
    }
}