<?php

namespace App\Services\Promotion;

use App\Models\Config\Coupon;
use App\Models\Vendor\Vendor;
use App\Services\Integration\MagentoService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CouponService
{
    /**
     * Create coupon - Magento first, then local DB
     * 
     * @param array $data
     * @return Coupon
     * @throws \Exception
     */
    /**
     * Create coupon - Magento first, then local DB
     * 
     * @param array $data
     * @return Coupon
     * @throws \Exception
     */
    public function createCoupon(array $data): Coupon
    {
        // Only platform coupons sync to Magento
        if ($data['type'] === 'platform') {
            // Convert vendor UUID to integer ID
            $vendor = Vendor::where('uuid', $data['vendor_id'])->firstOrFail();
            $vendorId = $vendor->id;

            if (!$vendor->magento_base_url) {
                throw new \Exception('Vendor does not have Magento configured');
            }

            try {
                // STEP 1: Create in Magento first
                $magento = new MagentoService($vendor);

                // Build sales rule data
                $salesRuleData = $this->buildSalesRuleData($data);

                // Create sales rule in Magento
                $ruleResponse = $magento->createSalesRule($salesRuleData);

                Log::info('Magento Create Sales Rule Response', [
                    'full_response' => $ruleResponse,
                    'code' => $data['code']
                ]);

                $magentoRuleId = $ruleResponse['rule_id'] ?? null;

                if (!$magentoRuleId) {
                    throw new \Exception('Failed to create sales rule in Magento: No rule_id returned');
                }

                // Create coupon code in Magento
                $couponResponse = $magento->createCoupon(
                    $magentoRuleId,
                    $data['code'],
                    $data['max_uses'] ?? 0,
                    $data['per_customer_limit'] ?? 0
                );

                Log::info('Magento Create Coupon Response', [
                    'full_response' => $couponResponse,
                    'code' => $data['code']
                ]);

                $magentoCouponId = $couponResponse['coupon_id'] ?? null;

                if (!$magentoCouponId) {
                    $magento->deleteSalesRule($magentoRuleId);
                    throw new \Exception('Failed to create coupon in Magento: No coupon_id returned');
                }

                // STEP 2: Create in local DB with ALL required fields
                $createData = [
                    'uuid' => (string) Str::uuid(),
                    'code' => $data['code'],
                    'description' => $data['description'] ?? null,
                    'type' => $data['type'],
                    'vendor_id' => $vendorId,
                    'discount_type' => $data['discount_type'],
                    'discount_value' => $data['discount_value'] ?? 0,
                    'min_order_amount' => $data['min_order_amount'] ?? 0,
                    'max_uses' => $data['max_uses'] ?? null,
                    'used_count' => 0,
                    'usage_limit_per_transaction' => $data['usage_limit_per_transaction'] ?? 1,  // ADDED
                    'per_customer_limit' => $data['per_customer_limit'] ?? 1,
                    'exclude_sale_items' => $data['exclude_sale_items'] ?? false,  // ADDED
                    'spent_amount' => 0,  // ADDED
                    'applicable_to' => $data['applicable_to'] ?? 'all',
                    'starts_at' => $data['starts_at'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                    'magento_rule_id' => $ruleResponse['rule_id'],
                    'magento_coupon_id' => $couponResponse['coupon_id'],
                    'sync_status' => 'synced',
                ];

                $coupon = Coupon::create($createData);

                Log::info('Coupon created successfully', [
                    'coupon_id' => $coupon->id,
                    'code' => $data['code']
                ]);

                return $coupon;
            } catch (\Exception $e) {
                Log::error('Failed to create coupon', [
                    'code' => $data['code'],
                    'error' => $e->getMessage()
                ]);
                throw new \Exception('Magento coupon creation failed: ' . $e->getMessage());
            }
        }

        // For vendor coupons (no Magento sync)
        if (isset($data['vendor_id'])) {
            $vendor = Vendor::where('uuid', $data['vendor_id'])->firstOrFail();
            $data['vendor_id'] = $vendor->id;
        }

        $createData = [
            'uuid' => (string) Str::uuid(),
            'code' => $data['code'],
            'type' => $data['type'],
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'] ?? 0,
            'min_order_amount' => $data['min_order_amount'] ?? 0,
            'used_count' => 0,
            'usage_limit_per_transaction' => $data['usage_limit_per_transaction'] ?? 1,
            'per_customer_limit' => $data['per_customer_limit'] ?? 1,
            'exclude_sale_items' => $data['exclude_sale_items'] ?? false,
            'spent_amount' => 0,
            'applicable_to' => $data['applicable_to'] ?? 'all',
            'sync_status' => 'pending',
            'is_active' => $data['is_active'] ?? true,
            'description' => $data['description'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'max_uses' => $data['max_uses'] ?? null,
            'allowed_emails' => isset($data['allowed_emails']) ? json_encode($data['allowed_emails']) : null,
            'allowed_roles' => isset($data['allowed_roles']) ? json_encode($data['allowed_roles']) : null,
            'budget_limit' => $data['budget_limit'] ?? null,
            'applicable_ids' => isset($data['applicable_ids']) ? json_encode($data['applicable_ids']) : null,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ];

        return Coupon::create($createData);
    }

    /**
     * Map Magento simple_action back to local discount_type
     * 
     * @param string $magentoAction
     * @return string
     */

    protected function mapMagentoActionToDiscountType(string $magentoAction): string
    {
        return match ($magentoAction) {
            'by_percent' => 'percentage',
            'cart_fixed' => 'fixed_amount',
            'buy_x_get_y' => 'buy_x_get_y',
            default => 'fixed_amount',
        };
    }
    /**
     * Update coupon - Magento first, then local DB
     * 
     * Flow:
     * 1. Update in Magento via API
     * 2. If successful, then update local DB
     * 3. If local DB fails, throw exception (Magento already updated)
     * 
     * @param Coupon $coupon
     * @param array $data
     * @return Coupon
     * @throws \Exception
     */
    public function updateCoupon(Coupon $coupon, array $data): Coupon
    {
        // Only platform coupons sync to Magento
        if ($coupon->type === 'platform' && $coupon->magento_rule_id) {
            $vendor = $coupon->vendor;

            if (!$vendor || !$vendor->magento_base_url) {
                throw new \Exception('Vendor does not have Magento configured');
            }

            try {
                // STEP 1: Update in Magento first
                $magento = new MagentoService($vendor);

                // Prepare update data (only send fields that are being updated)
                $updateData = $this->buildSalesRuleUpdateData($data, $coupon);

                // Update sales rule in Magento
                $magento->updateSalesRule($coupon->magento_rule_id, $updateData);

                // Update coupon specific fields if needed
                if (isset($data['max_uses']) || isset($data['per_customer_limit'])) {
                    $couponUpdateData = [];
                    if (isset($data['max_uses'])) {
                        $couponUpdateData['usage_limit'] = $data['max_uses'];
                    }
                    if (isset($data['per_customer_limit'])) {
                        $couponUpdateData['usage_per_customer'] = $data['per_customer_limit'];
                    }

                    if (!empty($couponUpdateData)) {
                        $couponUpdateData['coupon_id'] = $coupon->magento_coupon_id;
                        $magento->updateCoupon($coupon->magento_coupon_id, ['coupon' => $couponUpdateData]);
                    }
                }

                // Update coupon status in Magento if status changed
                if (isset($data['is_active']) && $data['is_active'] !== $coupon->is_active) {
                    $magento->updateSalesRuleStatus($coupon->magento_rule_id, $data['is_active']);
                }

                // STEP 2: Update in local DB
                // Remove any UUID fields that shouldn't be updated
                unset($data['uuid']);

                // Convert vendor UUID to integer ID if provided as string
                if (isset($data['vendor_id']) && is_string($data['vendor_id'])) {
                    $vendorModel = Vendor::where('uuid', $data['vendor_id'])->first();
                    if ($vendorModel) {
                        $data['vendor_id'] = $vendorModel->id;
                    }
                }

                $coupon->update($data);

                Log::info('Coupon updated successfully in Magento and Local DB', [
                    'coupon_id' => $coupon->id,
                    'coupon_uuid' => $coupon->uuid,
                    'updates' => array_keys($data)
                ]);

                return $coupon->fresh();
            } catch (\Exception $e) {
                Log::error('Failed to update coupon in Magento, local DB not updated', [
                    'coupon_id' => $coupon->id,
                    'error' => $e->getMessage()
                ]);
                throw new \Exception('Magento coupon update failed: ' . $e->getMessage());
            }
        }

        // For vendor coupons or coupons without Magento IDs
        unset($data['uuid']);

        // Convert vendor UUID to integer ID if provided as string
        if (isset($data['vendor_id']) && is_string($data['vendor_id'])) {
            $vendorModel = Vendor::where('uuid', $data['vendor_id'])->first();
            if ($vendorModel) {
                $data['vendor_id'] = $vendorModel->id;
            }
        }

        $coupon->update($data);
        return $coupon->fresh();
    }

    /**
     * Delete coupon - Magento first, then local DB
     * 
     * Flow:
     * 1. Delete from Magento via API
     * 2. If successful, then delete from local DB
     * 3. If local DB fails, throw exception (Magento already deleted)
     * 
     * @param Coupon $coupon
     * @return bool
     * @throws \Exception
     */
    public function deleteCoupon(Coupon $coupon): bool
    {
        // Check if coupon has been used
        if ($coupon->used_count > 0) {
            throw new \Exception('Cannot delete coupon that has been used. Consider deactivating it instead.');
        }

        // Only platform coupons sync to Magento
        if ($coupon->type === 'platform' && ($coupon->magento_rule_id || $coupon->magento_coupon_id)) {
            $vendor = $coupon->vendor;

            if (!$vendor || !$vendor->magento_base_url) {
                throw new \Exception('Vendor does not have Magento configured');
            }

            try {
                // STEP 1: Delete from Magento first
                $magento = new MagentoService($vendor);

                // Delete coupon if exists
                if ($coupon->magento_coupon_id) {
                    $magento->deleteCoupon($coupon->magento_coupon_id);
                    Log::info('Coupon deleted from Magento', [
                        'coupon_id' => $coupon->id,
                        'magento_coupon_id' => $coupon->magento_coupon_id
                    ]);
                }

                // Delete sales rule if exists
                if ($coupon->magento_rule_id) {
                    $magento->deleteSalesRule($coupon->magento_rule_id);
                    Log::info('Sales rule deleted from Magento', [
                        'coupon_id' => $coupon->id,
                        'magento_rule_id' => $coupon->magento_rule_id
                    ]);
                }

                // STEP 2: Delete from local DB
                $coupon->delete();

                Log::info('Coupon deleted successfully from Magento and Local DB', [
                    'coupon_id' => $coupon->id,
                    'code' => $coupon->code
                ]);

                return true;
            } catch (\Exception $e) {
                Log::error('Failed to delete coupon from Magento, local DB not updated', [
                    'coupon_id' => $coupon->id,
                    'error' => $e->getMessage()
                ]);
                throw new \Exception('Magento coupon deletion failed: ' . $e->getMessage());
            }
        }

        // For vendor coupons or coupons without Magento IDs
        $coupon->delete();
        return true;
    }

    /**
     * Toggle coupon status - Magento first, then local DB
     * 
     * Flow:
     * 1. Update status in Magento via API
     * 2. If successful, then update local DB
     * 3. If local DB fails, throw exception (Magento already updated)
     * 
     * @param Coupon $coupon
     * @return Coupon
     * @throws \Exception
     */
    public function toggleStatus(Coupon $coupon): Coupon
    {
        $newStatus = !$coupon->is_active;

        // Only platform coupons sync to Magento
        if ($coupon->type === 'platform' && $coupon->magento_rule_id) {
            $vendor = $coupon->vendor;

            if (!$vendor || !$vendor->magento_base_url) {
                throw new \Exception('Vendor does not have Magento configured');
            }

            try {
                // STEP 1: Update status in Magento first
                $magento = new MagentoService($vendor);
                $magento->updateSalesRuleStatus($coupon->magento_rule_id, $newStatus);

                Log::info('Coupon status updated in Magento', [
                    'coupon_id' => $coupon->id,
                    'new_status' => $newStatus,
                    'magento_rule_id' => $coupon->magento_rule_id
                ]);

                // STEP 2: Update local DB
                $coupon->is_active = $newStatus;
                $coupon->save();

                Log::info('Coupon status updated successfully in Magento and Local DB', [
                    'coupon_id' => $coupon->id,
                    'is_active' => $newStatus
                ]);

                return $coupon;
            } catch (\Exception $e) {
                Log::error('Failed to update coupon status in Magento, local DB not updated', [
                    'coupon_id' => $coupon->id,
                    'error' => $e->getMessage()
                ]);
                throw new \Exception('Magento status update failed: ' . $e->getMessage());
            }
        }

        // For vendor coupons or coupons without Magento IDs
        $coupon->is_active = $newStatus;
        $coupon->save();

        return $coupon;
    }

    /**
     * Resync coupon from Magento (pull latest data)
     * Useful for recovery when local DB is out of sync
     * 
     * @param Coupon $coupon
     * @return Coupon
     * @throws \Exception
     */
    public function resyncFromMagento(Coupon $coupon): Coupon
    {
        if ($coupon->type !== 'platform' || !$coupon->magento_rule_id) {
            throw new \Exception('Cannot resync: Not a platform coupon or missing Magento rule ID');
        }

        $vendor = $coupon->vendor;
        if (!$vendor || !$vendor->magento_base_url) {
            throw new \Exception('Vendor does not have Magento configured');
        }

        try {
            $magento = new MagentoService($vendor);

            // Get latest rule data from Magento
            $ruleData = $magento->getSalesRule($coupon->magento_rule_id);

            // Get coupon data
            if ($coupon->magento_coupon_id) {
                $couponData = $magento->getCoupon($coupon->magento_coupon_id);
            } else {
                // Search by rule_id
                $searchResult = $magento->searchCoupons(['rule_id' => $coupon->magento_rule_id]);
                $couponData = $searchResult['items'][0] ?? null;
            }

            // Update local DB with Magento data
            $updateData = [
                'is_active' => $ruleData['is_active'] ?? $coupon->is_active,
                'sync_status' => 'synced',
                'used_count' => $couponData['times_used'] ?? $coupon->used_count,
            ];

            $coupon->update($updateData);

            Log::info('Coupon resynced from Magento', [
                'coupon_id' => $coupon->id,
                'magento_rule_id' => $coupon->magento_rule_id
            ]);

            return $coupon->fresh();
        } catch (\Exception $e) {
            Log::error('Failed to resync coupon from Magento', [
                'coupon_id' => $coupon->id,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Magento resync failed: ' . $e->getMessage());
        }
    }

    /**
     * Build sales rule data for creation
     * 
     * @param array $data
     * @return array
     */
    protected function buildSalesRuleData(array $data): array
    {
        return [
            'rule' => [
                'rule_id' => 0,  // 0 for new rule
                'name' => $data['code'],
                'store_labels' => [],
                'description' => $data['description'] ?? $data['code'],
                'website_ids' => $this->getWebsiteIdsFromVendor($data['vendor_id'] ?? null),
                'customer_group_ids' => $this->getCustomerGroupIdsFromData($data),
                'from_date' => isset($data['starts_at']) ? date('Y-m-d', strtotime($data['starts_at'])) : null,
                'to_date' => isset($data['expires_at']) ? date('Y-m-d', strtotime($data['expires_at'])) : null,
                'uses_per_customer' => $data['per_customer_limit'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
                'condition' => $this->buildCondition($data),
                'action_condition' => $this->buildActionCondition(),
                'stop_rules_processing' => false,
                'is_advanced' => true,
                'product_ids' => [],
                'sort_order' => 0,
                'simple_action' => $this->getMagentoAction($data['discount_type']),
                'discount_amount' => (float) ($data['discount_value'] ?? 0),
                'discount_qty' => 0,
                'discount_step' => 1,
                'apply_to_shipping' => ($data['discount_type'] === 'free_shipping'),
                'times_used' => 0,
                'is_rss' => false,
                'coupon_type' => 'SPECIFIC_COUPON',  // or 'NO_COUPON', 'AUTO'
                'use_auto_generation' => false,
                'uses_per_coupon' => $data['max_uses'] ?? 0,
                'simple_free_shipping' => ($data['discount_type'] === 'free_shipping') ? '1' : '0',
                // 'extension_attributes' => [
                //     'reward_points_delta' => 0
                // ]
            ]
        ];
    }

    /**
     * Build sales rule update data (only changed fields)
     * 
     * @param array $newData
     * @param Coupon $existingCoupon
     * @return array
     */
    protected function buildSalesRuleUpdateData(array $newData, Coupon $existingCoupon): array
    {
        $updateData = ['rule' => ['rule_id' => $existingCoupon->magento_rule_id]];

        // Map local fields to Magento fields
        $fieldMapping = [
            'code' => 'name',
            'description' => 'description',
            'discount_value' => 'discount_amount',
            'max_uses' => 'uses_per_coupon',
            'per_customer_limit' => 'uses_per_customer',
            'starts_at' => 'from_date',
            'expires_at' => 'to_date',
        ];

        foreach ($fieldMapping as $localField => $magentoField) {
            if (array_key_exists($localField, $newData)) {
                if (in_array($localField, ['starts_at', 'expires_at']) && $newData[$localField]) {
                    $updateData['rule'][$magentoField] = date('Y-m-d', strtotime($newData[$localField]));
                } else {
                    $updateData['rule'][$magentoField] = $newData[$localField];
                }
            }
        }

        // Handle discount type change
        if (isset($newData['discount_type'])) {
            $updateData['rule']['simple_action'] = $this->getMagentoAction($newData['discount_type']);
            $updateData['rule']['apply_to_shipping'] = ($newData['discount_type'] === 'free_shipping');
        }

        // Handle min order amount condition
        if (isset($newData['min_order_amount'])) {
            if ($newData['min_order_amount'] > 0) {
                $updateData['rule']['condition'] = $this->buildConditionArray($newData['min_order_amount']);
            } else {
                $updateData['rule']['condition'] = null;
            }
        }

        return $updateData;
    }

    /**
     * Build condition array for minimum order amount
     * 
     * @param float $minAmount
     * @return array
     */
    protected function buildConditionArray(float $minAmount): array
    {
        return [
            'condition' => [
                'type' => 'Magento\SalesRule\Model\Rule\Condition\Combine',
                'aggregator' => 'all',
                'value' => 1,
                'conditions' => [
                    [
                        'type' => 'Magento\SalesRule\Model\Rule\Condition\Address',
                        'attribute' => 'base_subtotal',
                        'operator' => '>=',
                        'value' => $minAmount,
                    ]
                ]
            ]
        ];
    }
    protected function buildCondition(array $data): array
    {
        if (!empty($data['min_order_amount']) && $data['min_order_amount'] > 0) {
            return [
                'condition_type' => 'Magento\SalesRule\Model\Rule\Condition\Combine',
                'conditions' => [
                    [
                        'condition_type' => 'Magento\SalesRule\Model\Rule\Condition\Address',
                        'aggregator_type' => 'all',
                        'operator' => '>=',
                        'attribute_name' => 'base_subtotal',
                        'value' => (string) $data['min_order_amount'],
                    ]
                ],
                'aggregator_type' => 'all',
                'operator' => null,
                'attribute_name' => null,
                'value' => null,
            ];
        }

        return [
            'condition_type' => 'Magento\SalesRule\Model\Rule\Condition\Combine',
            'conditions' => [],
            'aggregator_type' => 'all',
            'operator' => null,
            'attribute_name' => null,
            'value' => null,
        ];
    }

    protected function buildActionCondition(): array
    {
        return [
            'condition_type' => 'Magento\SalesRule\Model\Rule\Condition\Product\Combine',
            'conditions' => [],
            'aggregator_type' => 'all',
            'operator' => null,
            'attribute_name' => null,
            'value' => null,
        ];
    }
    /**
     * Get Magento action type based on discount type
     * 
     * @param string $discountType
     * @return string
     */
    protected function getMagentoAction(string $discountType): string
    {
        return match ($discountType) {
            'percentage' => 'by_percent',
            'fixed_amount' => 'cart_fixed',
            'free_shipping' => 'cart_fixed',
            'buy_x_get_y' => 'buy_x_get_y',
            default => 'cart_fixed',
        };
    }

    /**
     * Get website IDs from vendor (using integer ID or UUID)
     * 
     * @param string|int|null $vendorIdentifier
     * @return array
     */
    protected function getWebsiteIdsFromVendor($vendorIdentifier): array
    {
        if ($vendorIdentifier) {
            if (is_string($vendorIdentifier) && !is_numeric($vendorIdentifier)) {
                $vendor = Vendor::where('uuid', $vendorIdentifier)->first();
                if ($vendor && $vendor->magento_website_id) {
                    return [(int) $vendor->magento_website_id];
                }
            } elseif (is_numeric($vendorIdentifier)) {
                $vendor = Vendor::find($vendorIdentifier);
                if ($vendor && $vendor->magento_website_id) {
                    return [(int) $vendor->magento_website_id];
                }
            }
        }
        return [1];
    }

    /**
     * Get customer group IDs from data
     * 
     * @param array $data
     * @return array
     */
    protected function getCustomerGroupIdsFromData(array $data): array
    {
        if (!empty($data['allowed_roles'])) {
            $mapping = [
                'guest' => 0,
                'not_logged_in' => 0,
                'general' => 1,
                'wholesale' => 2,
                'retailer' => 3,
            ];

            return array_map(function ($role) use ($mapping) {
                return $mapping[strtolower($role)] ?? 1;
            }, $data['allowed_roles']);
        }

        return [0, 1, 2, 3]; // All customer groups
    }
}
