<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Vendor\Vendor;
use App\Services\Integration\MagentoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerService
{
    protected ?Vendor $vendor = null;
    protected ?MagentoService $magentoService = null;

    public function forVendor(Vendor $vendor): self
    {
        $this->vendor = $vendor;
        $this->magentoService = new MagentoService($vendor);
        return $this;
    }

    protected function magento(): MagentoService
    {
        if (!$this->magentoService) {
            throw new \RuntimeException('Vendor not set. Call forVendor() first.');
        }
        return $this->magentoService;
    }

    /**
     * Get all customers from local DB (READ)
     */
    public function getAllCustomers(array $filters = []): array
    {
        $query = Customer::forVendor($this->vendor->id)->with('addresses');

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['is_subscribed'])) {
            $query->where('is_subscribed', filter_var($filters['is_subscribed'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('firstname', 'like', "%{$search}%")
                    ->orWhere('lastname', 'like', "%{$search}%");
            });
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        if (isset($filters['per_page'])) {
            $customers = $query->paginate($filters['per_page']);
            return [
                'data' => $customers->items(),
                'total' => $customers->total(),
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'last_page' => $customers->lastPage()
            ];
        }

        return [
            'data' => $query->get()->toArray(),
            'total' => $query->count()
        ];
    }

    /**
     * Get single customer by UUID (READ)
     */
    public function getCustomerByUuid(string $uuid): ?array
    {
        $customer = Customer::forVendor($this->vendor->id)
            ->with('addresses')
            ->where('uuid', $uuid)
            ->first();

        return $customer ? $customer->toArray() : null;
    }

    /**
     * Get customer by email (READ)
     */
    public function getCustomerByEmail(string $email): ?array
    {
        $customer = Customer::forVendor($this->vendor->id)
            ->with('addresses')
            ->where('email', $email)
            ->first();

        return $customer ? $customer->toArray() : null;
    }

    /**
     * Create customer (WRITE: Magento → Local)
     */
    public function createCustomer(array $data): array
    {
        DB::beginTransaction();

        try {
            // Prepare customer data matching Magento's expected structure
            $customerData = [
                'customer' => [
                    'email' => $data['email'],
                    'firstname' => $data['firstname'],
                    'lastname' => $data['lastname'],
                    'store_id' => (int)($data['magento_store_id'] ?? 1),
                    'website_id' => (int)($data['magento_website_id'] ?? 1),
                ]
            ];

            // Add optional fields
            if (isset($data['password']) && !empty($data['password'])) {
                $customerData['password'] = $data['password'];
            }
            if (isset($data['prefix']) && !empty($data['prefix'])) {
                $customerData['customer']['prefix'] = $data['prefix'];
            }
            if (isset($data['middlename']) && !empty($data['middlename'])) {
                $customerData['customer']['middlename'] = $data['middlename'];
            }
            if (isset($data['suffix']) && !empty($data['suffix'])) {
                $customerData['customer']['suffix'] = $data['suffix'];
            }
            if (isset($data['dob']) && !empty($data['dob'])) {
                $customerData['customer']['dob'] = $data['dob'];
            }
            if (isset($data['gender']) && !empty($data['gender'])) {
                $customerData['customer']['gender'] = (int)$data['gender'];
            }
            if (isset($data['taxvat']) && !empty($data['taxvat'])) {
                $customerData['customer']['taxvat'] = $data['taxvat'];
            }
            if (isset($data['is_active'])) {
                $customerData['customer']['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            }

            // Handle address if provided - Matches Magento's address structure
            if (isset($data['addresses']) && !empty($data['addresses']) && isset($data['addresses'][0])) {
                $address = $data['addresses'][0];

                $addressData = [
                    'firstname' => $address['firstname'] ?? $data['firstname'],
                    'lastname' => $address['lastname'] ?? $data['lastname'],
                    'street' => is_array($address['street']) ? $address['street'] : [$address['street']],
                    'city' => $address['city'],
                    'country_id' => $address['country_id'],
                    'postcode' => $address['postcode'],
                    'telephone' => $address['telephone'],
                    'default_billing' => $address['default_billing'] ?? false,
                    'default_shipping' => $address['default_shipping'] ?? false,
                ];

                // Add company if provided
                if (isset($address['company']) && !empty($address['company'])) {
                    $addressData['company'] = $address['company'];
                }

                // Add fax if provided
                if (isset($address['fax']) && !empty($address['fax'])) {
                    $addressData['fax'] = $address['fax'];
                }

                // Add VAT ID if provided
                if (isset($address['vat_id']) && !empty($address['vat_id'])) {
                    $addressData['vat_id'] = $address['vat_id'];
                }

                // ✅ FIXED: Handle region properly - check if it's string or array
                if (isset($address['region_id']) && !empty($address['region_id'])) {
                    $addressData['region'] = [
                        'region_id' => (int)$address['region_id']
                    ];
                } elseif (isset($address['region']) && !empty($address['region'])) {
                    // Check if region is a string
                    if (is_string($address['region'])) {
                        $addressData['region'] = [
                            'region' => $address['region'],
                            'region_code' => $address['region_code'] ?? substr($address['region'], 0, 2)
                        ];
                    }
                    // If region is already an array, use it directly
                    elseif (is_array($address['region'])) {
                        $addressData['region'] = $address['region'];
                    }
                }

                $customerData['customer']['addresses'] = [$addressData];
            }

            // Handle newsletter subscription - Matches extension_attributes structure
            if (isset($data['is_subscribed']) && $data['is_subscribed']) {
                $customerData['customer']['extension_attributes'] = [
                    'is_subscribed' => true
                ];
            }

            // Log the payload for debugging
            Log::info('Magento Create Customer Payload', $customerData);

            // Create customer in Magento
            $magentoCustomer = $this->magento()->post('customers', $customerData);

            // Sync to local database
            $localCustomer = $this->syncFromMagento($magentoCustomer);

            DB::commit();

            return [
                'success' => true,
                'data' => [
                    'uuid' => $localCustomer->uuid,
                    'internal_id' => $localCustomer->id,
                    'magento_id' => $localCustomer->magento_id,
                    'email' => $localCustomer->email,
                    'name' => $localCustomer->full_name,
                ],
                'message' => 'Customer created successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Customer creation failed', [
                'vendor_id' => $this->vendor->id,
                'email' => $data['email'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Failed to create customer: ' . $e->getMessage());
        }
    }

    /**
     * Update customer (WRITE: Magento → Local)
     */
    public function updateCustomer(string $uuid, array $data): array
    {
        $localCustomer = Customer::forVendor($this->vendor->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $customerData = ['customer' => []];

            // Only include fields that are provided
            if (isset($data['firstname'])) $customerData['customer']['firstname'] = $data['firstname'];
            if (isset($data['lastname'])) $customerData['customer']['lastname'] = $data['lastname'];
            if (isset($data['email'])) $customerData['customer']['email'] = $data['email'];
            if (isset($data['prefix'])) $customerData['customer']['prefix'] = $data['prefix'];
            if (isset($data['middlename'])) $customerData['customer']['middlename'] = $data['middlename'];
            if (isset($data['suffix'])) $customerData['customer']['suffix'] = $data['suffix'];
            if (isset($data['dob'])) $customerData['customer']['dob'] = $data['dob'];
            if (isset($data['gender'])) $customerData['customer']['gender'] = (int)$data['gender'];
            if (isset($data['taxvat'])) $customerData['customer']['taxvat'] = $data['taxvat'];
            if (isset($data['is_active'])) $customerData['customer']['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            if (isset($data['password']) && !empty($data['password'])) $customerData['password'] = $data['password'];

            // Handle subscription update
            if (isset($data['is_subscribed'])) {
                $customerData['customer']['extension_attributes']['is_subscribed'] = filter_var($data['is_subscribed'], FILTER_VALIDATE_BOOLEAN);
            }

            if (!empty($customerData['customer']) || isset($customerData['password'])) {
                Log::info('Updating customer in Magento', $customerData);

                $updatedMagentoCustomer = $this->magento()->put("customers/{$localCustomer->magento_id}", $customerData);
                $this->syncFromMagento($updatedMagentoCustomer);
            }

            DB::commit();

            return [
                'success' => true,
                'data' => [
                    'uuid' => $localCustomer->uuid,
                    'email' => $data['email'] ?? $localCustomer->email,
                ],
                'message' => 'Customer updated successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Customer update failed', [
                'vendor_id' => $this->vendor->id,
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Failed to update customer: ' . $e->getMessage());
        }
    }

    /**
     * Delete customer (WRITE: Magento → Local)
     */
    public function deleteCustomer(string $uuid): array
    {
        $localCustomer = Customer::forVendor($this->vendor->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $this->magento()->delete("customers/{$localCustomer->magento_id}");
            $localCustomer->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Customer deleted successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Customer deletion failed', [
                'vendor_id' => $this->vendor->id,
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Failed to delete customer: ' . $e->getMessage());
        }
    }

    /**
     * Sync all customers from Magento to local
     */
    public function syncAllCustomers(): array
    {
        DB::beginTransaction();

        try {
            $pageSize = 100;
            $currentPage = 1;
            $syncedCount = 0;
            $errors = [];

            do {
                $response = $this->magento()->get('customers/search', [
                    'searchCriteria[pageSize]' => $pageSize,
                    'searchCriteria[currentPage]' => $currentPage
                ]);

                $customers = $response['items'] ?? [];

                foreach ($customers as $magentoCustomer) {
                    try {
                        $this->syncFromMagento($magentoCustomer);
                        $syncedCount++;
                    } catch (\Exception $e) {
                        $errors[] = [
                            'email' => $magentoCustomer['email'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ];
                    }
                }

                $currentPage++;
                $totalCount = $response['total_count'] ?? 0;
            } while (($currentPage - 1) * $pageSize < $totalCount);

            DB::commit();

            return [
                'success' => true,
                'message' => "Synced {$syncedCount} customers successfully",
                'data' => [
                    'synced_count' => $syncedCount,
                    'errors' => $errors
                ]
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Customer sync failed', [
                'vendor_id' => $this->vendor->id,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Failed to sync customers: ' . $e->getMessage());
        }
    }

    /**
     * Sync single customer from Magento data to local
     */
    protected function syncFromMagento(array $magentoCustomer): Customer
    {
        $customer = Customer::updateOrCreate(
            [
                'vendor_id' => $this->vendor->id,
                'magento_id' => $magentoCustomer['id']
            ],
            [
                'email' => $magentoCustomer['email'],
                'firstname' => $magentoCustomer['firstname'],
                'lastname' => $magentoCustomer['lastname'],
                'middlename' => $magentoCustomer['middlename'] ?? null,
                'prefix' => $magentoCustomer['prefix'] ?? null,
                'suffix' => $magentoCustomer['suffix'] ?? null,
                'is_active' => $magentoCustomer['is_active'] ?? true,
                'is_subscribed' => $magentoCustomer['extension_attributes']['is_subscribed'] ?? false,
                'dob' => $magentoCustomer['dob'] ?? null,
                'gender' => $magentoCustomer['gender'] ?? null,
                'taxvat' => $magentoCustomer['taxvat'] ?? null,
                'group_id' => $magentoCustomer['group_id'] ?? null,
                'default_billing' => $magentoCustomer['default_billing'] ?? null,
                'default_shipping' => $magentoCustomer['default_shipping'] ?? null,
                'magento_store_id' => $magentoCustomer['store_id'] ?? null,
                'magento_website_id' => $magentoCustomer['website_id'] ?? null,
                'magento_data' => $magentoCustomer,
                'last_synced_at' => now(),
                'magento_updated_at' => $magentoCustomer['updated_at'] ?? now()
            ]
        );

        // Sync addresses
        if (isset($magentoCustomer['addresses'])) {
            foreach ($magentoCustomer['addresses'] as $address) {
                $this->syncAddressFromMagento($address, $customer->id);
            }
        }

        return $customer;
    }

    /**
     * Sync address from Magento to local
     */
    protected function syncAddressFromMagento(array $magentoAddress, int $customerId): CustomerAddress
    {
        return CustomerAddress::updateOrCreate(
            [
                'customer_id' => $customerId,
                'vendor_id' => $this->vendor->id,
                'magento_id' => $magentoAddress['id']
            ],
            [
                'firstname' => $magentoAddress['firstname'],
                'lastname' => $magentoAddress['lastname'],
                'middlename' => $magentoAddress['middlename'] ?? null,
                'prefix' => $magentoAddress['prefix'] ?? null,
                'suffix' => $magentoAddress['suffix'] ?? null,
                'company' => $magentoAddress['company'] ?? null,
                'street' => is_array($magentoAddress['street'])
                    ? implode(', ', $magentoAddress['street'])
                    : $magentoAddress['street'],
                'city' => $magentoAddress['city'],
                'region' => $magentoAddress['region']['region'] ?? $magentoAddress['region'] ?? null,
                'region_id' => $magentoAddress['region']['region_id'] ?? $magentoAddress['region_id'] ?? null,
                'postcode' => $magentoAddress['postcode'],
                'country_id' => $magentoAddress['country_id'],
                'telephone' => $magentoAddress['telephone'],
                'fax' => $magentoAddress['fax'] ?? null,
                'vat_id' => $magentoAddress['vat_id'] ?? null,
                'is_default_billing' => $magentoAddress['default_billing'] ?? false,
                'is_default_shipping' => $magentoAddress['default_shipping'] ?? false,
                'magento_data' => $magentoAddress,
                'last_synced_at' => now()
            ]
        );
    }
}
