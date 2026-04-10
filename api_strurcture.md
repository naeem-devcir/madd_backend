# MADD Commerce Platform - Laravel API Developer Guide

**Version:** 1.0  
**Tech Stack:** Laravel 10/11, PHP 8.2+, MySQL, Redis  
**Goal:** Build a scalable, secure backend for a Multi-Vendor EU E-commerce Platform.

---

## 1. Project Overview
We are building the API layer for **MADD Commerce**. This platform allows vendors to create stores in 26+ EU countries. 
*   **Core:** Laravel handles Users, Vendors, Settlements, MLM, and Returns.
*   **Commerce Engine:** Magento handles Products, Cart, and Checkout (Headless).
*   **Communication:** Laravel talks to Magento via API/Webhooks.

---

## 2. Folder Structure (Simplified)
We use a **Domain-Driven** structure to keep code organized by business feature, not just technical type.

```text
/app
  /Domains             # Business Logic (The "What")
    /Vendor            # Vendor management
    /Settlement        # Money & Payouts
    /Order             # Order processing
    /MLM               # Network & Referrals
  /Http
    /Controllers       # Handle Requests (The "Entry")
    /Requests          # Validation Rules
    /Resources         # JSON Output Formatting
  /Core                # Shared Tools
    /Services          # Magento, Stripe, SAP connections
    /Traits            # Reusable code
/routes
  api.php              # Main route file
  /api                 # Split routes (vendor.php, admin.php)
/database
  /migrations          # Database changes
```

---

## 3. API Rules & Standards

### 3.1. URL Naming
*   Use **plural nouns**, lowercase, and hyphens.
*   Include version in the URL.
*   **Good:** `POST /api/v1/orders`
*   **Bad:** `POST /api/v1/createOrder`

### 3.2. HTTP Methods
| Method | Purpose | Example |
| :--- | :--- | :--- |
| `GET` | Read data | `GET /api/v1/products` |
| `POST` | Create data | `POST /api/v1/orders` |
| `PUT` | Update full data | `PUT /api/v1/vendors/1` |
| `PATCH` | Update partial data | `PATCH /api/v1/vendors/1/status` |
| `DELETE` | Remove data | `DELETE /api/v1/products/1` |

### 3.3. Response Format
Always return JSON. Keep it consistent.

**Success:**
```json
{
  "success": true,
  "data": { ... },
  "message": "Operation successful"
}
```

**Error:**
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Invalid input data",
    "details": { "email": ["The email field is required."] }
  }
}
```

### 3.4. Pagination & Filtering
For lists (like Orders or Products), use query parameters.
*   **URL:** `GET /api/v1/orders?page=1&limit=20&status=pending`
*   **Laravel Tool:** Use `spatie/laravel-query-builder` for easy filtering.

---

## 4. Authentication & Security

### 4.1. Login System
*   **Package:** `laravel/passport` (OAuth2).
*   **Token:** JWT (JSON Web Token).
*   **Flow:** User logs in → Server gives Token → User sends Token with every request.

### 4.2. Roles (Who can do what?)
We use `spatie/laravel-permission`.
1.  **Admin:** Full access to all 20 Admin Modules.
2.  **Vendor:** Access to Vendor Panel (10 Modules), only sees their own data.
3.  **Customer:** Access to storefront, orders, profile.

### 4.3. Security Checklist
*   [ ] **HTTPS:** Enforced everywhere.
*   [ ] **Rate Limiting:** Max 100 requests/minute per user (Prevent abuse).
*   [ ] **GDPR:** Encrypt sensitive data (Bank accounts, Tax IDs).
*   [ ] **PCI DSS:** Never store Credit Card numbers (Use Stripe/PayPal tokens).

---

## 5. Database Strategy
We use **Multiple Connections** to keep data safe and fast.

| Connection Name | Purpose | Data Stored |
| :--- | :--- | :--- |
| `madd_main` | Core Platform | Users, Vendors, Stores, Roles |
| `madd_financial` | Money | Settlements, Payouts, Invoices |
| `madd_mlm` | Network | Referrals, Hierarchy, Commissions |
| `magento_core` | Commerce | Products, Orders (Read Mostly) |

**Laravel Config:**
```php
// In your Model
protected $connection = 'madd_financial';
```

---

## 6. Module Mapping (Business to API)
Map the **MADD Docx Modules** to API Groups.

### 6.1. Admin APIs (Central Control)
| Docx Module | API Group | Example Endpoint |
| :--- | :--- | :--- |
| **Module 1-2** (Auth/Dashboard) | `/admin/auth` | `GET /admin/dashboard-stats` |
| **Module 3** (Stores) | `/admin/stores` | `POST /admin/stores/{id}/approve` |
| **Module 6** (Vendors) | `/admin/vendors` | `GET /admin/vendors?plan=premium` |
| **Module 7** (Settlements) | `/admin/settlements` | `POST /admin/settlements/payout` |
| **Module 13** (MLM) | `/admin/mlm` | `GET /admin/mlm/network-tree` |
| **Module 16** (Returns) | `/admin/returns` | `PUT /admin/returns/{id}/status` |

### 6.2. Vendor APIs (Self Service)
| Docx Module | API Group | Example Endpoint |
| :--- | :--- | :--- |
| **Module 1-2** (Login/Dash) | `/vendor/auth` | `GET /vendor/dashboard` |
| **Module 3** (My Space) | `/vendor/store` | `POST /vendor/store/create` |
| **Module 5** (Orders) | `/vendor/orders` | `GET /vendor/orders?status=new` |
| **Module 6** (Products) | `/vendor/products` | `POST /vendor/products/import` |
| **Module 7** (SEO) | `/vendor/seo` | `PUT /vendor/products/{id}/meta` |

---

## 7. Critical Implementation Examples

### 7.1. Create Order (With Magento Sync)
*Logic: Create in Laravel → Sync to Magento → Trigger MLM Commission.*

```php
// app/Domains/Order/Actions/CreateOrderAction.php
public function execute($data) {
    return DB::transaction(function () use ($data) {
        // 1. Create Order in Laravel
        $order = Order::create($data);
        
        // 2. Sync to Magento (Headless Core)
        $this->magentoService->createOrder($order);
        
        // 3. Trigger MLM Commission Calculation (Async)
        CalculateCommissionJob::dispatch($order);
        
        return $order;
    });
}
```

### 7.2. Vendor Payout (Settlement)
*Logic: Check Balance → Call Stripe Connect → Record Transaction.*

```php
// app/Domains/Settlement/Jobs/ProcessPayoutJob.php
public function handle() {
    // Use Financial DB Connection
    $payout = Payout::on('madd_financial')->find($this->id);
    
    // 1. Calculate Net Amount (Sales - Commission - Returns)
    $amount = $this->calculator->calculateNet($payout->vendor);
    
    // 2. Send Money via Stripe
    $transfer = Stripe::transfers()->create([...]);
    
    // 3. Save Record
    $payout->update(['status' => 'paid', 'transaction_id' => $transfer->id]);
}
```

### 7.3. Request Return (RMA)
*Logic: Customer Request → Vendor Approval → Generate Label.*

```php
// Route: POST /api/v1/returns
public function store(Request $request) {
    $validated = $request->validate([
        'order_id' => 'required|exists:orders',
        'items' => 'required|array',
        'reason' => 'required'
    ]);

    $return = ReturnRequest::create($validated);
    
    // Notify Vendor
    Notification::send($return->vendor, new ReturnRequested($return));
    
    return new ReturnResource($return);
}
```

---

## 8. Queues & Jobs (Background Tasks)
Do not slow down the API. Use **Redis Queues** for heavy tasks.

| Task | Priority | Queue Name |
| :--- | :--- | :--- |
| Send Email/SMS | Low | `notifications` |
| Sync with Magento | High | `magento_sync` |
| Calculate MLM Commission | Medium | `mlm_calc` |
| Generate PDF Invoice | Low | `pdf_gen` |
| Process Payouts | High | `financial` |

**Tool:** Use **Laravel Horizon** to monitor queues.

---

## 9. Development Checklist
Before pushing code, ensure:

1.  [ ] **Validation:** Is all input validated using Form Requests?
2.  [ ] **Tests:** Are there PHPUnit/Pest tests for this feature?
3.  [ ] **Security:** Is the endpoint protected by Auth/Role Middleware?
4.  [ ] **Logging:** Are errors logged to `storage/logs`?
5.  [ ] **Docs:** Is the API endpoint documented (Swagger/Scribe)?
6.  [ ] **GDPR:** Are we storing only necessary personal data?

---

## 10. Getting Started (Quick Commands)

```bash
# 1. Install Dependencies
composer install

# 2. Setup Environment
cp .env.example .env
php artisan key:generate

# 3. Run Migrations (All DBs)
php artisan migrate --database=madd_main
php artisan migrate --database=madd_financial

# 4. Start Queue Worker
php artisan horizon

# 5. Run Tests
php artisan test
```

---

**Note to Developers:** 
This platform is **Financially Critical**. Always use Database Transactions (`DB::transaction`) when moving money or changing order status. If something fails, everything must roll back safely.