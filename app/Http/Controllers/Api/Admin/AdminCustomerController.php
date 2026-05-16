<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Models\Customer;
use App\Services\Customer\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class AdminCustomerController extends Controller
{
    protected CustomerService $customerService;

    public function __construct(CustomerService $customerService)
    {
        $this->customerService = $customerService;
    }

    /**
     * Get all customers (READ from local DB)
     */
    public function index(Request $request, string $vendorUuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $filters = [
                'is_subscribed' => $request->get('is_subscribed'),
                'search' => $request->get('search'),
                'sort_by' => $request->get('sort_by', 'created_at'),
                'sort_order' => $request->get('sort_order', 'desc'),
                'per_page' => $request->get('per_page')
            ];

            $result = $this->customerService
                ->forVendor($vendor)
                ->getAllCustomers($filters);

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'vendor' => [
                    'uuid' => $vendor->uuid,
                    'name' => $vendor->name,
                ],
                'meta' => isset($result['current_page']) ? [
                    'current_page' => $result['current_page'],
                    'per_page' => $result['per_page'],
                    'total' => $result['total'],
                    'last_page' => $result['last_page']
                ] : null
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customers',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get single customer (READ from local DB)
     */
    public function show(Request $request, string $vendorUuid, string $uuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $customer = $this->customerService
                ->forVendor($vendor)
                ->getCustomerByUuid($uuid);

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $customer
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customer'
            ], 500);
        }
    }

    /**
     * Get customer by email (READ)
     */
    public function byEmail(Request $request, string $vendorUuid, string $email): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $customer = $this->customerService
                ->forVendor($vendor)
                ->getCustomerByEmail($email);

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $customer
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customer'
            ], 500);
        }
    }

    /**
     * Create customer (WRITE: Magento → Local)
     */
    public function store(Request $request, string $vendorUuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'email' => 'required|email|unique:customers,email',
                'firstname' => 'required|string|max:255',
                'lastname' => 'required|string|max:255',
                'prefix' => 'nullable|string|max:50',
                'middlename' => 'nullable|string|max:255',
                'suffix' => 'nullable|string|max:50',
                'dob' => 'nullable|date',
                'gender' => 'nullable|integer|in:1,2,3',
                'taxvat' => 'nullable|string|max:50',
                'is_subscribed' => 'boolean',
                'magento_store_id' => 'nullable|string',
                'magento_website_id' => 'nullable|string',
                'addresses' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->customerService
                ->forVendor($vendor)
                ->createCustomer($request->all());

            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update customer (WRITE: Magento → Local)
     */
    public function update(Request $request, string $vendorUuid, string $uuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'email' => 'sometimes|email|unique:customers,email,' . $uuid . ',uuid',
                'firstname' => 'sometimes|string|max:255',
                'lastname' => 'sometimes|string|max:255',
                'prefix' => 'nullable|string|max:50',
                'middlename' => 'nullable|string|max:255',
                'suffix' => 'nullable|string|max:50',
                'dob' => 'nullable|date',
                'gender' => 'nullable|integer|in:1,2,3',
                'taxvat' => 'nullable|string|max:50',
                'is_subscribed' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->customerService
                ->forVendor($vendor)
                ->updateCustomer($uuid, $request->all());

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete customer (WRITE: Magento → Local)
     */
    public function destroy(string $vendorUuid, string $uuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $result = $this->customerService
                ->forVendor($vendor)
                ->deleteCustomer($uuid);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync all customers from Magento
     */
    public function sync(string $vendorUuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $result = $this->customerService
                ->forVendor($vendor)
                ->syncAllCustomers();

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}