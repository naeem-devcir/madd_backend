<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// ============================================
// API Version prefix
// ============================================
Route::prefix('v1')->group(function () {

    // ============================================
    // PUBLIC ROUTES (No Authentication Required)
    // ============================================

    // Health Check
    Route::get('health', function () {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'version' => '1.0.0',
        ]);
    });

    // ============================================
    // AUTHENTICATION ROUTES
    // ============================================
    // Route::prefix('auth')->group(function () {
    //     // Login & Registration
    //     Route::post('login', [Api\Auth\AuthController::class, 'login']);
    //     Route::post('register', [Api\Auth\AuthController::class, 'register']);
    //     Route::post('refresh', [Api\Auth\AuthController::class, 'refresh']);

    //     // Password Reset
    //     Route::post('forgot-password', [Api\Auth\PasswordResetController::class, 'forgot']);
    //     Route::post('reset-password', [Api\Auth\PasswordResetController::class, 'reset']);

    //     // Email Verification
    //     Route::get('verify-email/{id}/{hash}', [Api\Auth\EmailVerificationController::class, 'verify'])
    //         ->name('verification.verify');
    //     Route::post('resend-verification', [Api\Auth\EmailVerificationController::class, 'resend']);

    //     // Social Login
    //     Route::get('social/{provider}/redirect', [Api\Auth\SocialLoginController::class, 'redirect']);
    //     Route::post('social/{provider}/callback', [Api\Auth\SocialLoginController::class, 'handle']);
    // });

    Route::prefix('auth')->group(function () {
        // Login & Registration
        Route::post('login', [Api\Auth\AuthController::class, 'login']);
        Route::post('register', [Api\Auth\AuthController::class, 'register']);
        Route::post('refresh', [Api\Auth\AuthController::class, 'refresh']);

        // Password Reset - FIX: Add named routes
        Route::post('forgot-password', [Api\Auth\PasswordResetController::class, 'forgot'])
            ->name('password.email');

        Route::post('reset-password', [Api\Auth\PasswordResetController::class, 'reset'])
            ->name('password.update');

        // This is the missing route - for email reset link
        Route::get('reset-password/{token}', function ($token) {
            return response()->json([
                'token' => $token,
                'message' => 'Use this token to reset your password',
            ]);
        })->name('password.reset');

        // Email Verification
        Route::get('verify-email/{id}/{hash}', [Api\Auth\EmailVerificationController::class, 'verify'])
            ->name('verification.verify');
        Route::post('resend-verification', [Api\Auth\EmailVerificationController::class, 'resend']);

        // Social Login
        Route::get('social/{provider}/redirect', [Api\Auth\SocialLoginController::class, 'redirect']);
        Route::post('social/{provider}/callback', [Api\Auth\SocialLoginController::class, 'handle']);
    });

    // ============================================
    // PUBLIC CATALOG ROUTES
    // ============================================

    // Store Information
    Route::get('stores/{slug}', [Api\Vendor\VendorStoreController::class, 'publicShow']);
    Route::get('stores/{slug}/info', [Api\Vendor\VendorStoreController::class, 'publicInfo']);

    // Product Catalog (Public)
    Route::prefix('catalog')->group(function () {
        Route::get('products', [Api\Product\ProductCatalogController::class, 'index']);

        // Specific routes with static segments (place these FIRST)
        Route::get('products/sku/{sku}', [Api\Product\ProductCatalogController::class, 'showBySku']);
        Route::get('products/{id}/related', [Api\Product\ProductCatalogController::class, 'related']);

        // Generic dynamic routes (place these LAST)
        Route::get('products/{storeSlug}/{productSlug}', [Api\Product\ProductCatalogController::class, 'show']);

        // Categories
        Route::get('categories', [Api\Product\ProductCategoryController::class, 'index']);
        Route::get('categories/{slug}', [Api\Product\ProductCategoryController::class, 'show']);
        Route::get('categories/{slug}/products', [Api\Product\ProductCategoryController::class, 'products']);

        // Search
        Route::get('search', [Api\Product\ProductSearchController::class, 'search']);
        Route::get('suggest', [Api\Product\ProductSearchController::class, 'suggest']);
    });

    // Public Reviews
    Route::get('products/{productId}/reviews', [Api\Product\ProductReviewController::class, 'index']);
    Route::get('products/{productId}/reviews/summary', [Api\Product\ProductReviewController::class, 'summary']);

    // ============================================
    // AUTHENTICATED ROUTES (All Users)
    // ============================================
    Route::middleware(['auth:sanctum'])->group(function () {

        // User Profile
        Route::prefix('user')->group(function () {
            Route::get('profile', [Api\Auth\AuthController::class, 'profile']);
            Route::put('profile', [Api\Auth\AuthController::class, 'updateProfile']);
            Route::post('change-password', [Api\Auth\AuthController::class, 'changePassword']);
            Route::post('logout', [Api\Auth\AuthController::class, 'logout']);
            Route::delete('account', [Api\Auth\AuthController::class, 'deleteAccount']);

            // 2FA
            Route::prefix('2fa')->group(function () {
                Route::post('enable', [Api\Auth\TwoFactorController::class, 'enable']);
                Route::post('verify', [Api\Auth\TwoFactorController::class, 'verify']);
                Route::post('disable', [Api\Auth\TwoFactorController::class, 'disable']);
                Route::get('recovery-codes', [Api\Auth\TwoFactorController::class, 'recoveryCodes']);
            });
        });

        // Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/', [Api\Notification\NotificationController::class, 'index']);
            Route::get('preferences', [Api\Notification\NotificationController::class, 'preferences']);
            Route::put('preferences', [Api\Notification\NotificationController::class, 'updatePreferences']);
            Route::put('{id}/read', [Api\Notification\NotificationController::class, 'markAsRead']);
            Route::put('read-all', [Api\Notification\NotificationController::class, 'markAllAsRead']);
            Route::delete('{id}', [Api\Notification\NotificationController::class, 'destroy']);
        });

        // Wishlist (Available to all authenticated users)
        Route::prefix('wishlist')->group(function () {
            Route::get('/', [Api\Customer\CustomerWishlistController::class, 'index']);
            Route::post('{productId}', [Api\Customer\CustomerWishlistController::class, 'add']);
            Route::delete('{productId}', [Api\Customer\CustomerWishlistController::class, 'remove']);
            Route::delete('/', [Api\Customer\CustomerWishlistController::class, 'clear']);
            Route::post('{productId}/move-to-cart', [Api\Customer\CustomerWishlistController::class, 'moveToCart']);
        });
    });

    // ============================================
    // CUSTOMER ROUTES
    // ============================================
    Route::middleware(['auth:sanctum', 'role:customer'])->prefix('customer')->group(function () {

        // Orders
        Route::prefix('orders')->group(function () {
            Route::get('/', [Api\Customer\CustomerOrderController::class, 'index']);
            Route::get('{id}', [Api\Customer\CustomerOrderController::class, 'show']);
            Route::post('{id}/cancel', [Api\Customer\CustomerOrderController::class, 'cancel']);
            Route::get('{id}/tracking', [Api\Customer\CustomerOrderController::class, 'tracking']);
            Route::get('{id}/invoice', [Api\Customer\CustomerOrderController::class, 'downloadInvoice']);
        });

        // Returns
        Route::prefix('returns')->group(function () {
            Route::get('/', [Api\Order\ReturnController::class, 'index']);
            Route::post('order/{orderId}', [Api\Order\ReturnController::class, 'create']);
            Route::get('{id}', [Api\Order\ReturnController::class, 'show']);
            Route::post('{id}/cancel', [Api\Order\ReturnController::class, 'cancel']);
            Route::get('{id}/label', [Api\Order\ReturnController::class, 'downloadLabel']);
        });

        // Reviews
        Route::apiResource('reviews', Api\Customer\CustomerReviewController::class);
        Route::post('reviews/{id}/helpful', [Api\Customer\CustomerReviewController::class, 'markHelpful']);
        Route::post('reviews/{id}/flag', [Api\Customer\CustomerReviewController::class, 'flag']);

        // Address Book
        Route::apiResource('addresses', Api\Customer\CustomerAddressController::class);
        Route::put('addresses/{id}/default', [Api\Customer\CustomerAddressController::class, 'setDefault']);

        // Payment Methods
        Route::get('payment-methods', [Api\Customer\CustomerPaymentController::class, 'index']);
        Route::delete('payment-methods/{id}', [Api\Customer\CustomerPaymentController::class, 'destroy']);
    });

    // ============================================
    // VENDOR ROUTES
    // ============================================
    Route::middleware(['auth:sanctum', 'role:vendor'])->prefix('vendor')->group(function () {

        // Dashboard
        Route::get('dashboard', [Api\Vendor\VendorDashboardController::class, 'index']);
        Route::get('statistics', [Api\Vendor\VendorDashboardController::class, 'statistics']);

    // Profile Management
        Route::prefix('profile')->group(function () {
            Route::get('/', [Api\Vendor\VendorProfileController::class, 'show']);
            Route::put('/', [Api\Vendor\VendorProfileController::class, 'update']);
            Route::get('onboarding', [Api\Vendor\VendorProfileController::class, 'onboardingStatus']);
            Route::put('onboarding/step', [Api\Vendor\VendorProfileController::class, 'updateOnboardingStep']);
        });

        // Store Management
        Route::prefix('stores')->group(function () {
            Route::get('/', [Api\Vendor\VendorStoreController::class, 'index']);
            Route::post('/', [Api\Vendor\VendorStoreController::class, 'store']);
            Route::get('{id}', [Api\Vendor\VendorStoreController::class, 'show']);
            Route::put('{id}', [Api\Vendor\VendorStoreController::class, 'update']);
            Route::delete('{id}', [Api\Vendor\VendorStoreController::class, 'destroy']);
            Route::post('{id}/activate', [Api\Vendor\VendorStoreController::class, 'activate']);
            Route::post('{id}/deactivate', [Api\Vendor\VendorStoreController::class, 'deactivate']);
            Route::post('{id}/domain', [Api\Vendor\VendorStoreController::class, 'addDomain']);
            Route::delete('{id}/domain/{domainId}', [Api\Vendor\VendorStoreController::class, 'removeDomain']);
        });

        // Product Management
        Route::prefix('products')->group(function () {
            Route::get('/', [Api\Vendor\VendorProductController::class, 'index']);
            Route::post('/', [Api\Vendor\VendorProductController::class, 'store']);
            Route::get('drafts', [Api\Vendor\VendorProductController::class, 'drafts']);
            Route::get('{product:uuid}', [Api\Vendor\VendorProductController::class, 'show']);
            Route::put('{product:uuid}', [Api\Vendor\VendorProductController::class, 'update']);
            Route::delete('{product:uuid}', [Api\Vendor\VendorProductController::class, 'destroy']);
            Route::put('{product:uuid}/inventory', [Api\Vendor\VendorProductController::class, 'updateInventory']);
            Route::post('{product:uuid}/duplicate', [Api\Vendor\VendorProductController::class, 'duplicate']);
            Route::post('{product:uuid}/bulk-price', [Api\Vendor\VendorProductController::class, 'bulkPriceUpdate']);
        });

        // Order Management
        Route::prefix('orders')->group(function () {
            Route::get('/', [Api\Vendor\VendorOrderController::class, 'index']);
            Route::get('statistics', [Api\Vendor\VendorOrderController::class, 'statistics']);
            Route::get('{id}', [Api\Vendor\VendorOrderController::class, 'show']);
            Route::put('{id}/status', [Api\Vendor\VendorOrderController::class, 'updateStatus']);
            Route::post('{id}/ship', [Api\Vendor\VendorOrderController::class, 'createShipment']);
            Route::post('{id}/invoice', [Api\Vendor\VendorOrderController::class, 'generateInvoice']);
            Route::get('{id}/invoice', [Api\Vendor\VendorOrderController::class, 'downloadInvoice']);
        });

        // Returns Management
        Route::prefix('returns')->group(function () {
            Route::get('/', [Api\Vendor\VendorReturnController::class, 'index']);
            Route::get('{id}', [Api\Vendor\VendorReturnController::class, 'show']);
            Route::post('{id}/approve', [Api\Vendor\VendorReturnController::class, 'approve']);
            Route::post('{id}/reject', [Api\Vendor\VendorReturnController::class, 'reject']);
            Route::post('{id}/receive', [Api\Vendor\VendorReturnController::class, 'markAsReceived']);
            Route::post('{id}/refund', [Api\Vendor\VendorReturnController::class, 'processRefund']);
        });

        // Settlements & Banking
        Route::prefix('settlements')->group(function () {
            Route::get('/', [Api\Vendor\VendorSettlementController::class, 'index']);
            Route::get('summary', [Api\Vendor\VendorSettlementController::class, 'summary']);
            Route::get('{id}', [Api\Vendor\VendorSettlementController::class, 'show']);
            Route::get('{id}/download', [Api\Vendor\VendorSettlementController::class, 'downloadStatement']);
        });

        Route::prefix('transactions')->group(function () {
            Route::get('/', [Api\Vendor\VendorSettlementController::class, 'transactions']);
            Route::get('export', [Api\Vendor\VendorSettlementController::class, 'exportTransactions']);
        });

        Route::prefix('payouts')->group(function () {
            Route::get('/', [Api\Vendor\VendorSettlementController::class, 'payouts']);
            Route::post('request', [Api\Vendor\VendorSettlementController::class, 'requestPayout']);
        });

        // Banking Accounts
        Route::apiResource('bank-accounts', Api\Vendor\VendorBankingController::class);
        Route::post('bank-accounts/{id}/verify', [Api\Vendor\VendorBankingController::class, 'verify']);
        Route::post('bank-accounts/{id}/primary', [Api\Vendor\VendorBankingController::class, 'setPrimary']);

        // Shipping Configuration
        Route::prefix('shipping')->group(function () {
            Route::get('methods', [Api\Vendor\VendorShippingController::class, 'methods']);
            Route::put('methods', [Api\Vendor\VendorShippingController::class, 'updateMethods']);
            Route::get('zones', [Api\Vendor\VendorShippingController::class, 'zones']);
            Route::post('zones', [Api\Vendor\VendorShippingController::class, 'createZone']);
            Route::put('zones/{id}', [Api\Vendor\VendorShippingController::class, 'updateZone']);
            Route::delete('zones/{id}', [Api\Vendor\VendorShippingController::class, 'deleteZone']);
        });

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('sales', [Api\Vendor\VendorReportController::class, 'sales']);
            Route::get('products', [Api\Vendor\VendorReportController::class, 'products']);
            Route::get('customers', [Api\Vendor\VendorReportController::class, 'customers']);
            Route::get('inventory', [Api\Vendor\VendorReportController::class, 'inventory']);
            Route::post('export', [Api\Vendor\VendorReportController::class, 'export']);
        });

        // Team Management
        Route::prefix('team')->group(function () {
            Route::get('/', [Api\Vendor\VendorTeamController::class, 'index']);
            Route::post('/', [Api\Vendor\VendorTeamController::class, 'invite']);
            Route::delete('{userId}', [Api\Vendor\VendorTeamController::class, 'remove']);
            Route::put('{userId}/role', [Api\Vendor\VendorTeamController::class, 'updateRole']);
            Route::post('invitations/{invitationId}/resend', [Api\Vendor\VendorTeamController::class, 'resendInvitation']);
        });

        // API Keys (for integrations)
        Route::prefix('api-keys')->group(function () {
            Route::get('/', [Api\Vendor\VendorApiKeyController::class, 'index']);
            Route::post('/', [Api\Vendor\VendorApiKeyController::class, 'store']);
            Route::delete('{id}', [Api\Vendor\VendorApiKeyController::class, 'destroy']);
            Route::post('{id}/regenerate', [Api\Vendor\VendorApiKeyController::class, 'regenerate']);
        });

        // Webhooks
        Route::prefix('webhooks')->group(function () {
            Route::get('/', [Api\Vendor\VendorWebhookController::class, 'index']);
            Route::post('/', [Api\Vendor\VendorWebhookController::class, 'store']);
            Route::put('{id}', [Api\Vendor\VendorWebhookController::class, 'update']);
            Route::delete('{id}', [Api\Vendor\VendorWebhookController::class, 'destroy']);
            Route::post('{id}/test', [Api\Vendor\VendorWebhookController::class, 'test']);
        });
    });

    // ============================================
    // ADMIN ROUTES
    // ============================================
    Route::middleware(['auth:sanctum', 'role:admin|super_admin'])->prefix('admin')->group(function () {

        // Dashboard
        Route::get('dashboard', [Api\Admin\AdminDashboardController::class, 'index']);
        Route::get('statistics', [Api\Admin\AdminDashboardController::class, 'statistics']);

        // User Management
        Route::prefix('users')->group(function () {
            Route::get('/', [Api\Admin\AdminUserController::class, 'index']);
            Route::get('{id}', [Api\Admin\AdminUserController::class, 'show']);
            Route::put('{id}/role', [Api\Admin\AdminUserController::class, 'assignRole']);
            Route::put('{id}/suspend', [Api\Admin\AdminUserController::class, 'suspend']);
            Route::put('{id}/activate', [Api\Admin\AdminUserController::class, 'activate']);
            Route::delete('{id}', [Api\Admin\AdminUserController::class, 'destroy']);
            Route::post('{id}/impersonate', [Api\Admin\AdminUserController::class, 'impersonate']);
        });

        // Vendor Management
        Route::prefix('vendors')->group(function () {
            Route::get('/', [Api\Admin\AdminVendorController::class, 'index']);
            Route::get('statistics', [Api\Admin\AdminVendorController::class, 'statistics']);
            Route::get('applications', [Api\Admin\AdminVendorController::class, 'applications']);
            Route::get('{id}', [Api\Admin\AdminVendorController::class, 'show']);
            Route::post('{id}/approve', [Api\Admin\AdminVendorController::class, 'approve']);
            Route::post('{id}/suspend', [Api\Admin\AdminVendorController::class, 'suspend']);
            Route::post('{id}/activate', [Api\Admin\AdminVendorController::class, 'activate']);
            Route::put('{id}/plan', [Api\Admin\AdminVendorController::class, 'updatePlan']);
            Route::post('{id}/kyc-verify', [Api\Admin\AdminVendorController::class, 'verifyKyc']);
            Route::post('{id}/kyc-reject', [Api\Admin\AdminVendorController::class, 'rejectKyc']);
        });

        // Product Management
        Route::prefix('products')->group(function () {
            Route::get('/', [Api\Admin\AdminProductController::class, 'index']);
            Route::get('statistics', [Api\Admin\AdminProductController::class, 'statistics']);
            Route::get('pending', [Api\Admin\AdminProductController::class, 'pending']);
            Route::get('{id}', [Api\Admin\AdminProductController::class, 'show']);
            Route::post('drafts/{id}/approve', [Api\Admin\AdminProductController::class, 'approve']);
            Route::post('drafts/{id}/reject', [Api\Admin\AdminProductController::class, 'reject']);
            Route::post('drafts/{id}/request-modification', [Api\Admin\AdminProductController::class, 'requestModification']);
            Route::delete('{id}', [Api\Admin\AdminProductController::class, 'destroy']);
            Route::post('{id}/feature', [Api\Admin\AdminProductController::class, 'feature']);
            Route::post('{id}/unfeature', [Api\Admin\AdminProductController::class, 'unfeature']);
        });

        // Order Management
        Route::prefix('orders')->group(function () {
            Route::get('/', [Api\Admin\AdminOrderController::class, 'index']);
            Route::get('statistics', [Api\Admin\AdminOrderController::class, 'statistics']);
            Route::get('{id}', [Api\Admin\AdminOrderController::class, 'show']);
            Route::put('{id}/status', [Api\Admin\AdminOrderController::class, 'updateStatus']);
            Route::post('{id}/refund', [Api\Admin\AdminOrderController::class, 'processRefund']);
            Route::post('{id}/cancel', [Api\Admin\AdminOrderController::class, 'cancel']);
        });

        // Settlement Management
        Route::prefix('settlements')->group(function () {
            Route::get('/', [Api\Admin\AdminSettlementController::class, 'index']);
            Route::get('pending', [Api\Admin\AdminSettlementController::class, 'pending']);
            Route::get('{id}', [Api\Admin\AdminSettlementController::class, 'show']);
            Route::post('generate', [Api\Admin\AdminSettlementController::class, 'generate']);
            Route::post('{id}/approve', [Api\Admin\AdminSettlementController::class, 'approve']);
            Route::post('{id}/pay', [Api\Admin\AdminSettlementController::class, 'markAsPaid']);
            Route::post('{id}/dispute', [Api\Admin\AdminSettlementController::class, 'dispute']);
            Route::get('{id}/statement', [Api\Admin\AdminSettlementController::class, 'downloadStatement']);
        });

        // Coupon Management
        Route::apiResource('coupons', Api\Admin\AdminCouponController::class);
        Route::post('coupons/{id}/duplicate', [Api\Admin\AdminCouponController::class, 'duplicate']);
        Route::post('coupons/{id}/sync', [Api\Admin\AdminCouponController::class, 'syncToMagento']);

        // Platform Settings
        Route::prefix('settings')->group(function () {
            Route::get('/', [Api\Admin\AdminSettingsController::class, 'index']);
            Route::put('/', [Api\Admin\AdminSettingsController::class, 'update']);
            Route::get('system', [Api\Admin\AdminSettingsController::class, 'system']);
            Route::get('payment', [Api\Admin\AdminSettingsController::class, 'payment']);
            Route::get('shipping', [Api\Admin\AdminSettingsController::class, 'shipping']);
            Route::get('tax', [Api\Admin\AdminSettingsController::class, 'tax']);
            Route::get('email', [Api\Admin\AdminSettingsController::class, 'email']);
        });

        // Platform Configuration
        Route::prefix('config')->group(function () {
            // Countries
            Route::apiResource('countries', Api\Admin\AdminCountryController::class);
            Route::post('countries/{code}/activate', [Api\Admin\AdminCountryController::class, 'activate']);

            // Sales Policies
            Route::apiResource('sales-policies', Api\Admin\AdminSalesPolicyController::class);

            // Currencies
            Route::apiResource('currencies', Api\Admin\AdminCurrencyController::class);
            Route::post('currencies/{code}/exchange-rate', [Api\Admin\AdminCurrencyController::class, 'updateExchangeRate']);

            // Languages
            Route::apiResource('languages', Api\Admin\AdminLanguageController::class);

            // Themes
            Route::apiResource('themes', Api\Admin\AdminThemeController::class);
            Route::post('themes/{id}/set-default', [Api\Admin\AdminThemeController::class, 'setDefault']);

            // Courier/Shipping
            Route::apiResource('couriers', Api\Admin\AdminCourierController::class);
            Route::post('couriers/{id}/test', [Api\Admin\AdminCourierController::class, 'testConnection']);
        });

        // Vendor Plans
        Route::apiResource('plans', Api\Admin\AdminPlanController::class);
        Route::post('plans/{id}/set-default', [Api\Admin\AdminPlanController::class, 'setDefault']);

        // MLM Management
        Route::prefix('mlm')->group(function () {
            Route::get('agents', [Api\Admin\AdminMLMController::class, 'index']);
            Route::get('agents/{id}', [Api\Admin\AdminMLMController::class, 'show']);
            Route::post('agents/{id}/verify', [Api\Admin\AdminMLMController::class, 'verify']);
            Route::get('commissions', [Api\Admin\AdminMLMController::class, 'commissions']);
            Route::post('commissions/process', [Api\Admin\AdminMLMController::class, 'processCommissions']);
            Route::post('commissions/{id}/pay', [Api\Admin\AdminMLMController::class, 'payCommission']);
            Route::get('structure', [Api\Admin\AdminMLMController::class, 'structure']);
            Route::get('levels', [Api\Admin\AdminMLMController::class, 'levels']);
            Route::put('levels', [Api\Admin\AdminMLMController::class, 'updateLevels']);
        });

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('platform', [Api\Admin\AdminReportController::class, 'platform']);
            Route::get('financial', [Api\Admin\AdminReportController::class, 'financial']);
            Route::get('sales', [Api\Admin\AdminReportController::class, 'sales']);
            Route::get('vendor-performance', [Api\Admin\AdminReportController::class, 'vendorPerformance']);
            Route::get('product-performance', [Api\Admin\AdminReportController::class, 'productPerformance']);
            Route::post('export', [Api\Admin\AdminReportController::class, 'export']);
            Route::get('export/{jobId}/download', [Api\Admin\AdminReportController::class, 'downloadExport']);
        });

        // System Management
        Route::prefix('system')->group(function () {
            Route::get('logs', [Api\Admin\AdminSystemController::class, 'logs']);
            Route::get('logs/{date}', [Api\Admin\AdminSystemController::class, 'showLog']);
            Route::delete('logs', [Api\Admin\AdminSystemController::class, 'clearLogs']);
            Route::get('cache', [Api\Admin\AdminSystemController::class, 'cache']);
            Route::post('cache/clear', [Api\Admin\AdminSystemController::class, 'clearCache']);
            Route::get('queues', [Api\Admin\AdminSystemController::class, 'queues']);
            Route::post('queues/retry/{id}', [Api\Admin\AdminSystemController::class, 'retryJob']);
            Route::delete('queues/failed', [Api\Admin\AdminSystemController::class, 'clearFailedJobs']);
            Route::get('maintenance', [Api\Admin\AdminSystemController::class, 'maintenanceStatus']);
            Route::post('maintenance', [Api\Admin\AdminSystemController::class, 'toggleMaintenance']);
        });
    });

    // ============================================
    // MLM AGENT ROUTES
    // ============================================
    Route::middleware(['auth:sanctum', 'role:mlm_agent'])->prefix('mlm')->group(function () {

        Route::get('dashboard', [Api\Mlm\MlmAgentController::class, 'dashboard']);
        Route::get('team', [Api\Mlm\MlmAgentController::class, 'team']);
        Route::get('team/{id}', [Api\Mlm\MlmAgentController::class, 'teamMember']);
        Route::get('commissions', [Api\Mlm\MlmAgentController::class, 'commissions']);
        Route::get('statistics', [Api\Mlm\MlmAgentController::class, 'statistics']);
        Route::post('invite', [Api\Mlm\MlmAgentController::class, 'inviteVendor']);
        Route::get('invitations', [Api\Mlm\MlmAgentController::class, 'invitations']);
        Route::get('structure', [Api\Mlm\MlmAgentController::class, 'structure']);
    });

    // ============================================
    // WEBHOOK ROUTES (No Authentication - Signature Verified)
    // ============================================
    Route::prefix('webhooks')->group(function () {

        // Magento Webhooks
        Route::prefix('magento')->group(function () {
            Route::post('order-created', [Api\Webhook\MagentoWebhookController::class, 'handleOrderCreated']);
            Route::post('order-updated', [Api\Webhook\MagentoWebhookController::class, 'handleOrderUpdated']);
            Route::post('order-cancelled', [Api\Webhook\MagentoWebhookController::class, 'handleOrderCancelled']);
            Route::post('product-created', [Api\Webhook\MagentoWebhookController::class, 'handleProductCreated']);
            Route::post('product-updated', [Api\Webhook\MagentoWebhookController::class, 'handleProductUpdated']);
            Route::post('product-deleted', [Api\Webhook\MagentoWebhookController::class, 'handleProductDeleted']);
            Route::post('inventory-updated', [Api\Webhook\MagentoWebhookController::class, 'handleInventoryUpdated']);
            Route::post('customer-created', [Api\Webhook\MagentoWebhookController::class, 'handleCustomerCreated']);
            Route::post('customer-updated', [Api\Webhook\MagentoWebhookController::class, 'handleCustomerUpdated']);
            Route::post('review-created', [Api\Webhook\MagentoWebhookController::class, 'handleReviewCreated']);
        });

        // Payment Gateway Webhooks
        Route::post('stripe', [Api\Webhook\StripeWebhookController::class, 'handle']);
        Route::post('paypal', [Api\Webhook\PayPalWebhookController::class, 'handle']);
        Route::post('adyen', [Api\Webhook\AdyenWebhookController::class, 'handle']);

        // Shipping Carrier Webhooks
        Route::prefix('carrier')->group(function () {
            Route::post('dhl', [Api\Webhook\CarrierWebhookController::class, 'handleDHL']);
            Route::post('ups', [Api\Webhook\CarrierWebhookController::class, 'handleUPS']);
            Route::post('fedex', [Api\Webhook\CarrierWebhookController::class, 'handleFedEx']);
            Route::post('dpd', [Api\Webhook\CarrierWebhookController::class, 'handleDPD']);
        });
    });

    // ============================================
    // INTEGRATION ROUTES (API Keys Required)
    // ============================================
    Route::middleware(['auth:api-key'])->prefix('integration')->group(function () {

        // Order API
        Route::prefix('orders')->group(function () {
            Route::get('/', [Api\Integration\OrderIntegrationController::class, 'index']);
            Route::get('{id}', [Api\Integration\OrderIntegrationController::class, 'show']);
            Route::put('{id}/status', [Api\Integration\OrderIntegrationController::class, 'updateStatus']);
            Route::post('{id}/ship', [Api\Integration\OrderIntegrationController::class, 'createShipment']);
        });

        // Product API
        Route::prefix('products')->group(function () {
            Route::get('/', [Api\Integration\ProductIntegrationController::class, 'index']);
            Route::get('{sku}', [Api\Integration\ProductIntegrationController::class, 'show']);
            Route::put('{sku}/inventory', [Api\Integration\ProductIntegrationController::class, 'updateInventory']);
            Route::put('{sku}/price', [Api\Integration\ProductIntegrationController::class, 'updatePrice']);
        });

        // Inventory API
        Route::prefix('inventory')->group(function () {
            Route::get('/', [Api\Integration\InventoryIntegrationController::class, 'index']);
            Route::post('batch', [Api\Integration\InventoryIntegrationController::class, 'batchUpdate']);
        });
    });
});

// ============================================
// FALLBACK ROUTE
// ============================================
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found',
        'errors' => [
            'route' => 'The requested API endpoint does not exist',
        ],
    ], 404);
});
