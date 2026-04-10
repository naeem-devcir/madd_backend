<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Config\CountryConfig;
use App\Models\Config\Currency;
use App\Models\Config\Language;
use App\Models\Config\SalesPolicy;
use App\Models\Config\MaddCompany;
use App\Models\Config\Courier;
use App\Models\Vendor\VendorPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminSettingsController extends Controller
{
    /**
     * Get all system settings
     */
    public function index(Request $request)
    {
        $group = $request->get('group');

        $settings = [
            'general' => $this->getGeneralSettings(),
            'payment' => $this->getPaymentSettings(),
            'shipping' => $this->getShippingSettings(),
            'tax' => $this->getTaxSettings(),
            'email' => $this->getEmailSettings(),
            'api' => $this->getApiSettings(),
            'security' => $this->getSecuritySettings(),
        ];

        if ($group && isset($settings[$group])) {
            return response()->json([
                'success' => true,
                'data' => $settings[$group]
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Update general settings
     */
    public function updateGeneral(Request $request)
    {
        $validated = $request->validate([
            'site_name' => 'required|string|max:255',
            'site_logo' => 'nullable|url',
            'site_favicon' => 'nullable|url',
            'contact_email' => 'required|email',
            'contact_phone' => 'nullable|string',
            'address' => 'nullable|string',
            'default_currency' => 'required|exists:currencies,code',
            'default_language' => 'required|exists:languages,code',
            'timezone' => 'required|timezone',
            'date_format' => 'required|string',
            'time_format' => 'required|string',
        ]);

        foreach ($validated as $key => $value) {
            setting([$key => $value]);
        }

        Cache::forget('system_settings');

        return response()->json([
            'success' => true,
            'message' => 'General settings updated successfully',
            'data' => $validated
        ]);
    }

    /**
     * Update payment settings
     */
    public function updatePayment(Request $request)
    {
        $validated = $request->validate([
            'stripe_enabled' => 'boolean',
            'stripe_key' => 'required_if:stripe_enabled,true|nullable|string',
            'stripe_secret' => 'required_if:stripe_enabled,true|nullable|string',
            'stripe_webhook_secret' => 'nullable|string',
            'paypal_enabled' => 'boolean',
            'paypal_client_id' => 'required_if:paypal_enabled,true|nullable|string',
            'paypal_secret' => 'required_if:paypal_enabled,true|nullable|string',
            'paypal_mode' => 'in:sandbox,live',
            'paypal_webhook_id' => 'nullable|string',
            'bank_transfer_enabled' => 'boolean',
            'bank_transfer_details' => 'required_if:bank_transfer_enabled,true|nullable|array',
            'default_payment_method' => 'string',
        ]);

        foreach ($validated as $key => $value) {
            setting([$key => $value]);
        }

        Cache::forget('payment_settings');

        return response()->json([
            'success' => true,
            'message' => 'Payment settings updated successfully',
            'data' => $validated
        ]);
    }

    /**
     * Update shipping settings
     */
    public function updateShipping(Request $request)
    {
        $validated = $request->validate([
            'default_carrier' => 'nullable|exists:couriers,id',
            'free_shipping_threshold' => 'nullable|numeric|min:0',
            'shipping_tax_class' => 'nullable|string',
            'international_shipping_enabled' => 'boolean',
        ]);

        foreach ($validated as $key => $value) {
            setting([$key => $value]);
        }

        Cache::forget('shipping_settings');

        return response()->json([
            'success' => true,
            'message' => 'Shipping settings updated successfully',
            'data' => $validated
        ]);
    }

    /**
     * Update tax settings
     */
    public function updateTax(Request $request)
    {
        $validated = $request->validate([
            'tax_calculation_method' => 'in:unit,row,total',
            'tax_based_on' => 'in:shipping,billing,origin',
            'default_tax_class' => 'nullable|string',
            'display_prices_with_tax' => 'boolean',
            'display_tax_totals' => 'boolean',
        ]);

        foreach ($validated as $key => $value) {
            setting([$key => $value]);
        }

        Cache::forget('tax_settings');

        return response()->json([
            'success' => true,
            'message' => 'Tax settings updated successfully',
            'data' => $validated
        ]);
    }

    /**
     * Update email settings
     */
    public function updateEmail(Request $request)
    {
        $validated = $request->validate([
            'mail_driver' => 'in:smtp,ses,sendmail,log',
            'mail_host' => 'required_if:mail_driver,smtp|nullable|string',
            'mail_port' => 'required_if:mail_driver,smtp|nullable|integer',
            'mail_username' => 'nullable|string',
            'mail_password' => 'nullable|string',
            'mail_encryption' => 'nullable|in:tls,ssl',
            'mail_from_address' => 'required|email',
            'mail_from_name' => 'required|string',
        ]);

        // Update .env file (in production, use queue job)
        foreach ($validated as $key => $value) {
            setting([$key => $value]);
        }

        Cache::forget('email_settings');

        return response()->json([
            'success' => true,
            'message' => 'Email settings updated successfully',
            'data' => $validated
        ]);
    }

    /**
     * Update API settings
     */
    public function updateApi(Request $request)
    {
        $validated = $request->validate([
            'api_rate_limit' => 'integer|min:10|max:1000',
            'api_rate_limit_per_minute' => 'integer|min:10|max:500',
            'webhook_retry_attempts' => 'integer|min:1|max:10',
            'webhook_retry_delay' => 'integer|min:5|max:3600',
            'enable_api_logging' => 'boolean',
        ]);

        foreach ($validated as $key => $value) {
            setting([$key => $value]);
        }

        Cache::forget('api_settings');

        return response()->json([
            'success' => true,
            'message' => 'API settings updated successfully',
            'data' => $validated
        ]);
    }

    /**
     * Update security settings
     */
    public function updateSecurity(Request $request)
    {
        $validated = $request->validate([
            'two_factor_required' => 'boolean',
            'session_timeout' => 'integer|min:5|max:720',
            'max_login_attempts' => 'integer|min:3|max:20',
            'lockout_duration' => 'integer|min:5|max:60',
            'password_expiry_days' => 'nullable|integer|min:30|max:365',
            'require_email_verification' => 'boolean',
            'require_phone_verification' => 'boolean',
        ]);

        foreach ($validated as $key => $value) {
            setting([$key => $value]);
        }

        Cache::forget('security_settings');

        return response()->json([
            'success' => true,
            'message' => 'Security settings updated successfully',
            'data' => $validated
        ]);
    }

    /**
     * Get countries list
     */
    public function getCountries()
    {
        $countries = CountryConfig::with('maddCompany')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $countries
        ]);
    }

    /**
     * Update country settings
     */
    public function updateCountry(Request $request, $code)
    {
        $country = CountryConfig::where('code', $code)->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'phone_code' => 'sometimes|string|max:10',
            'eu_member' => 'sometimes|boolean',
            'currency_code' => 'sometimes|exists:currencies,code',
            'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'timezone' => 'sometimes|string',
            'language_codes' => 'sometimes|array',
            'madd_company_id' => 'nullable|exists:madd_companies,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $country->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Country updated successfully',
            'data' => $country
        ]);
    }

    /**
     * Get currencies list
     */
    public function getCurrencies()
    {
        $currencies = Currency::orderBy('code')->get();

        return response()->json([
            'success' => true,
            'data' => $currencies
        ]);
    }

    /**
     * Update exchange rates
     */
    public function updateExchangeRates(Request $request)
    {
        $request->validate([
            'rates' => 'required|array',
            'rates.*.code' => 'required|exists:currencies,code',
            'rates.*.exchange_rate' => 'required|numeric|min:0.0001',
        ]);

        DB::beginTransaction();

        try {
            foreach ($request->rates as $rate) {
                Currency::where('code', $rate['code'])->update([
                    'exchange_rate' => $rate['exchange_rate']
                ]);
            }

            DB::commit();

            Cache::forget('exchange_rates');

            return response()->json([
                'success' => true,
                'message' => 'Exchange rates updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update exchange rates',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get languages list
     */
    public function getLanguages()
    {
        $languages = Language::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $languages
        ]);
    }

    /**
     * Get MADD companies
     */
    public function getMaddCompanies()
    {
        $companies = MaddCompany::with('country')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $companies
        ]);
    }

    /**
     * Create MADD company
     */
    public function createMaddCompany(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'country_code' => 'required|exists:country_configs,code',
            'vat_number' => 'required|string|max:50',
            'registration_number' => 'required|string|max:100',
            'legal_representative' => 'required|string',
            'contact_email' => 'required|email',
            'contact_phone' => 'nullable|string',
            'address' => 'required|array',
            'bank_details' => 'nullable|array',
            'invoice_prefix' => 'required|string|max:20',
            'fiscal_year_start' => 'required|date',
            'is_active' => 'boolean',
        ]);

        $company = MaddCompany::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'MADD company created successfully',
            'data' => $company
        ], 201);
    }

    /**
     * Get vendor plans
     */
    public function getVendorPlans()
    {
        $plans = VendorPlan::orderBy('price_monthly')->get();

        return response()->json([
            'success' => true,
            'data' => $plans
        ]);
    }

    /**
     * Create vendor plan
     */
    public function createVendorPlan(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:100|unique:vendor_plans,slug',
            'description' => 'nullable|string',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'required|numeric|min:0',
            'setup_fee' => 'numeric|min:0',
            'commission_rate' => 'required|numeric|min:0|max:100',
            'max_products' => 'integer|min:0',
            'max_stores' => 'integer|min:1',
            'max_users' => 'integer|min:1',
            'features' => 'nullable|array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'trial_period_days' => 'integer|min:0',
        ]);

        $plan = VendorPlan::create($validated);

        // If this is set as default, remove default from others
        if ($validated['is_default'] ?? false) {
            VendorPlan::where('id', '!=', $plan->id)->update(['is_default' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor plan created successfully',
            'data' => $plan
        ], 201);
    }

    /**
     * Update vendor plan
     */
    public function updateVendorPlan(Request $request, $id)
    {
        $plan = VendorPlan::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'slug' => 'sometimes|string|max:100|unique:vendor_plans,slug,' . $plan->id,
            'description' => 'nullable|string',
            'price_monthly' => 'sometimes|numeric|min:0',
            'price_yearly' => 'sometimes|numeric|min:0',
            'setup_fee' => 'numeric|min:0',
            'commission_rate' => 'sometimes|numeric|min:0|max:100',
            'max_products' => 'integer|min:0',
            'max_stores' => 'integer|min:1',
            'max_users' => 'integer|min:1',
            'features' => 'nullable|array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'trial_period_days' => 'integer|min:0',
        ]);

        $plan->update($validated);

        // If this is set as default, remove default from others
        if (isset($validated['is_default']) && $validated['is_default']) {
            VendorPlan::where('id', '!=', $plan->id)->update(['is_default' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor plan updated successfully',
            'data' => $plan
        ]);
    }

    /**
     * Delete vendor plan
     */
    public function deleteVendorPlan($id)
    {
        $plan = VendorPlan::findOrFail($id);

        // Check if any vendors are using this plan
        if ($plan->vendors()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete plan that has active vendors'
            ], 422);
        }

        $plan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Vendor plan deleted successfully'
        ]);
    }

    /**
     * Clear system cache
     */
    public function clearCache()
    {
        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => 'System cache cleared successfully'
        ]);
    }

    /**
     * Get system info
     */
    public function systemInfo()
    {
        $info = [
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'mysql_version' => DB::select('select version() as version')[0]->version,
            'redis_version' => $this->getRedisVersion(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'environment' => app()->environment(),
            'debug_mode' => config('app.debug'),
            'maintenance_mode' => app()->isDownForMaintenance(),
            'last_backup' => $this->getLastBackupDate(),
        ];

        return response()->json([
            'success' => true,
            'data' => $info
        ]);
    }

    /**
     * Get general settings
     */
    private function getGeneralSettings()
    {
        return [
            'site_name' => setting('site_name', 'MADD Commerce'),
            'site_logo' => setting('site_logo'),
            'site_favicon' => setting('site_favicon'),
            'contact_email' => setting('contact_email'),
            'contact_phone' => setting('contact_phone'),
            'address' => setting('address'),
            'default_currency' => setting('default_currency', 'EUR'),
            'default_language' => setting('default_language', 'en'),
            'timezone' => setting('timezone', 'UTC'),
            'date_format' => setting('date_format', 'Y-m-d'),
            'time_format' => setting('time_format', 'H:i:s'),
        ];
    }

    private function getPaymentSettings()
    {
        return [
            'stripe_enabled' => setting('stripe_enabled', false),
            'stripe_key' => setting('stripe_key'),
            'stripe_webhook_secret' => setting('stripe_webhook_secret'),
            'paypal_enabled' => setting('paypal_enabled', false),
            'paypal_client_id' => setting('paypal_client_id'),
            'paypal_mode' => setting('paypal_mode', 'sandbox'),
            'bank_transfer_enabled' => setting('bank_transfer_enabled', false),
            'bank_transfer_details' => setting('bank_transfer_details'),
            'default_payment_method' => setting('default_payment_method', 'stripe'),
        ];
    }

    private function getShippingSettings()
    {
        return [
            'default_carrier' => setting('default_carrier'),
            'free_shipping_threshold' => setting('free_shipping_threshold', 0),
            'shipping_tax_class' => setting('shipping_tax_class'),
            'international_shipping_enabled' => setting('international_shipping_enabled', false),
        ];
    }

    private function getTaxSettings()
    {
        return [
            'tax_calculation_method' => setting('tax_calculation_method', 'total'),
            'tax_based_on' => setting('tax_based_on', 'shipping'),
            'default_tax_class' => setting('default_tax_class'),
            'display_prices_with_tax' => setting('display_prices_with_tax', false),
            'display_tax_totals' => setting('display_tax_totals', true),
        ];
    }

    private function getEmailSettings()
    {
        return [
            'mail_driver' => setting('mail_driver', 'log'),
            'mail_host' => setting('mail_host'),
            'mail_port' => setting('mail_port'),
            'mail_username' => setting('mail_username'),
            'mail_encryption' => setting('mail_encryption'),
            'mail_from_address' => setting('mail_from_address'),
            'mail_from_name' => setting('mail_from_name'),
        ];
    }

    private function getApiSettings()
    {
        return [
            'api_rate_limit' => setting('api_rate_limit', 100),
            'api_rate_limit_per_minute' => setting('api_rate_limit_per_minute', 60),
            'webhook_retry_attempts' => setting('webhook_retry_attempts', 3),
            'webhook_retry_delay' => setting('webhook_retry_delay', 60),
            'enable_api_logging' => setting('enable_api_logging', true),
        ];
    }

    private function getSecuritySettings()
    {
        return [
            'two_factor_required' => setting('two_factor_required', false),
            'session_timeout' => setting('session_timeout', 120),
            'max_login_attempts' => setting('max_login_attempts', 5),
            'lockout_duration' => setting('lockout_duration', 30),
            'password_expiry_days' => setting('password_expiry_days'),
            'require_email_verification' => setting('require_email_verification', true),
            'require_phone_verification' => setting('require_phone_verification', false),
        ];
    }

    private function getRedisVersion()
    {
        try {
            $redis = app('redis')->connection();
            $info = $redis->info('server');
            return $info['redis_version'] ?? 'Unknown';
        } catch (\Exception $e) {
            return 'Not connected';
        }
    }

    private function getLastBackupDate()
    {
        // Implementation depends on backup system
        return null;
    }

    public function update(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Settings update is not implemented yet.',
        ], 501);
    }

    public function system()
    {
        return response()->json([
            'success' => true,
            'data' => $this->getSystemInfo(),
        ]);
    }

    public function payment()
    {
        return response()->json([
            'success' => true,
            'data' => $this->getPaymentSettings(),
        ]);
    }

    public function shipping()
    {
        return response()->json([
            'success' => true,
            'data' => $this->getShippingSettings(),
        ]);
    }

    public function tax()
    {
        return response()->json([
            'success' => true,
            'data' => $this->getTaxSettings(),
        ]);
    }

    public function email()
    {
        return response()->json([
            'success' => true,
            'data' => $this->getEmailSettings(),
        ]);
    }
}
