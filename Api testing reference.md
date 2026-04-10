# API Testing Reference
> Base URL: `https://your-domain.com/api/v1`
> Auth Header: `Authorization: Bearer {access_token}`
> Content-Type: `application/json`

---

## ✅ PUBLIC ROUTES (No Auth Required)

### Health Check
```
GET /health
```
No payload required.

---

## 🔐 AUTH ROUTES

### Register (Customer)
```
POST /auth/register
```
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": "john.doe@example.com",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "phone": "+923001234567",
  "user_type": "customer",
  "country_code": "PK",
  "locale": "en",
  "marketing_opt_in": true
}
```

### Register (Vendor)
```
POST /auth/register
```
```json
{
  "first_name": "Ali",
  "last_name": "Khan",
  "email": "ali.vendor@example.com",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "phone": "+923009876543",
  "user_type": "vendor",
  "country_code": "PK",
  "locale": "en",
  "company_name": "Khan Enterprises",
  "address_line1": "123 Main Street",
  "city": "Karachi",
  "postal_code": "74200",
  "marketing_opt_in": false
}
```

### Login
```
POST /auth/login
```
```json
{
  "email": "john.doe@example.com",
  "password": "Password123!",
  "device_name": "Postman Test",
  "bypass_verification": true
}
```

### Refresh Token
```
POST /auth/refresh
```
```json
{
  "refresh_token": "your-refresh-token-here"
}
```

### Forgot Password
```
POST /auth/forgot-password
```
```json
{
  "email": "john.doe@example.com"
}
```

### Reset Password
```
POST /auth/reset-password
```
```json
{
  "token": "reset-token-from-email",
  "email": "john.doe@example.com",
  "password": "NewPassword123!",
  "password_confirmation": "NewPassword123!"
}
```

### Verify Email
```
GET /auth/verify-email/{id}/{hash}
```
No payload. `id` and `hash` come from the verification email link.

### Resend Verification Email
```
POST /auth/resend-verification
```
```json
{
  "email": "john.doe@example.com"
}
```

### Social Login Redirect
```
GET /auth/social/google/redirect
```
No payload. Providers: `google`, `facebook`, `apple`

### Social Login Callback
```
POST /auth/social/google/callback
```
```json
{
  "code": "oauth-code-from-provider",
  "state": "random-state-string"
}
```

---

## 🏪 PUBLIC CATALOG ROUTES

### Get Store Info (Public)
```
GET /stores/khan-enterprises
```
```
GET /stores/khan-enterprises/info
```
No payload. Replace `khan-enterprises` with a real store slug.

### List Products (Catalog)
```
GET /catalog/products?page=1&per_page=20&store_slug=khan-enterprises&min_price=10&max_price=500
```
No payload. Query params: `page`, `per_page`, `store_slug`, `min_price`, `max_price`, `category_id`, `search`

### Get Product by Slug
```
GET /catalog/products/{storeSlug}/{productSlug}
```
Example: `GET /catalog/products/khan-enterprises/blue-sneakers`

### Get Product by SKU
```
GET /catalog/products/sku/SKU-001
```

### Get Related Products
```
GET /catalog/products/{id}/related
```

### List Categories
```
GET /catalog/categories
```

### Get Category by Slug
```
GET /catalog/categories/electronics
```

### Get Products in Category
```
GET /catalog/categories/electronics/products?page=1&per_page=20
```

### Search Products
```
GET /catalog/search?q=sneakers&category=footwear&min_price=50&max_price=300&page=1
```

### Suggest (Autocomplete)
```
GET /catalog/suggest?q=sneak
```

### Get Product Reviews (Public)
```
GET /products/{productId}/reviews?page=1&per_page=10
```

### Product Reviews Summary
```
GET /products/{productId}/reviews/summary
```

---

## 👤 AUTHENTICATED USER ROUTES
> Requires: `Authorization: Bearer {token}`

### Get Profile
```
GET /user/profile
```

### Update Profile
```
PUT /user/profile
```
```json
{
  "first_name": "John",
  "last_name": "Smith",
  "phone": "+923001234567",
  "avatar_url": "https://cdn.example.com/avatars/john.jpg",
  "locale": "en",
  "timezone": "Asia/Karachi",
  "marketing_opt_in": true
}
```

### Change Password
```
POST /user/change-password
```
```json
{
  "current_password": "Password123!",
  "new_password": "NewPassword456!",
  "new_password_confirmation": "NewPassword456!"
}
```

### Logout
```
POST /user/logout
```
```json
{
  "refresh_token": "your-refresh-token-here"
}
```

### Delete Account
```
DELETE /user/account
```
```json
{
  "password": "Password123!",
  "confirmation": "1"
}
```

### Enable 2FA
```
POST /user/2fa/enable
```
No payload.

### Verify 2FA
```
POST /user/2fa/verify
```
```json
{
  "code": "123456"
}
```

### Disable 2FA
```
POST /user/2fa/disable
```
```json
{
  "code": "123456"
}
```

### Get 2FA Recovery Codes
```
GET /user/2fa/recovery-codes
```

---

## 🔔 NOTIFICATIONS

### List Notifications
```
GET /notifications?page=1&per_page=20
```

### Get Notification Preferences
```
GET /notifications/preferences
```

### Update Preferences
```
PUT /notifications/preferences
```
```json
{
  "email_order_updates": true,
  "email_promotions": false,
  "sms_order_updates": true,
  "push_notifications": true
}
```

### Mark Notification as Read
```
PUT /notifications/{id}/read
```

### Mark All as Read
```
PUT /notifications/read-all
```

### Delete Notification
```
DELETE /notifications/{id}
```

---

## ❤️ WISHLIST

### Get Wishlist
```
GET /wishlist
```

### Add to Wishlist
```
POST /wishlist/{productId}
```
No payload.

### Remove from Wishlist
```
DELETE /wishlist/{productId}
```

### Clear Wishlist
```
DELETE /wishlist
```

### Move Item to Cart
```
POST /wishlist/{productId}/move-to-cart
```
```json
{
  "quantity": 1
}
```

---

## 🛍️ CUSTOMER ROUTES
> Requires: `role:customer` + Bearer token

### List Orders
```
GET /customer/orders?status=delivered&date_from=2025-01-01&date_to=2025-12-31&page=1&per_page=20
```

### Get Order
```
GET /customer/orders/{id}
```

### Cancel Order
```
POST /customer/orders/{id}/cancel
```
```json
{
  "reason": "Changed my mind about the purchase"
}
```

### Track Order
```
GET /customer/orders/{id}/tracking
```

### Download Invoice
```
GET /customer/orders/{id}/invoice
```

### List Returns
```
GET /customer/returns
```

### Create Return Request
```
POST /customer/returns/order/{orderId}
```
```json
{
  "items": [
    {
      "order_item_id": "item-uuid-here",
      "quantity": 1
    }
  ],
  "reason": "Product is defective - screen has a crack",
  "notes": "The crack appeared after 2 days of use with no physical damage"
}
```

### Get Return
```
GET /customer/returns/{id}
```

### Cancel Return
```
POST /customer/returns/{id}/cancel
```

### Download Return Label
```
GET /customer/returns/{id}/label
```

### List My Reviews
```
GET /customer/reviews
```

### Create Review
```
POST /customer/reviews
```
```json
{
  "product_id": "product-uuid-here",
  "order_id": "order-uuid-here",
  "rating": 4,
  "title": "Great quality sneakers",
  "body": "These sneakers are very comfortable and the build quality is excellent. I have been wearing them for a month and they still look brand new.",
  "images": [
    "https://cdn.example.com/reviews/img1.jpg",
    "https://cdn.example.com/reviews/img2.jpg"
  ]
}
```

### Update Review
```
PUT /customer/reviews/{id}
```
```json
{
  "rating": 5,
  "title": "Updated: Perfect sneakers!",
  "body": "After 2 months of use, I can confidently say these are the best sneakers I have owned. Updated my rating to 5 stars."
}
```

### Delete Review
```
DELETE /customer/reviews/{id}
```

### Mark Review Helpful
```
POST /customer/reviews/{id}/helpful
```

### Flag Review
```
POST /customer/reviews/{id}/flag
```
```json
{
  "reason": "spam"
}
```

### List Addresses
```
GET /customer/addresses
```

### Create Address
```
POST /customer/addresses
```
```json
{
  "label": "Home",
  "first_name": "John",
  "last_name": "Doe",
  "address_line1": "House 12, Street 5",
  "address_line2": "Block C, Gulshan-e-Iqbal",
  "city": "Karachi",
  "state": "Sindh",
  "postal_code": "75300",
  "country_code": "PK",
  "phone": "+923001234567",
  "is_default": true
}
```

### Get Address
```
GET /customer/addresses/{id}
```

### Update Address
```
PUT /customer/addresses/{id}
```
```json
{
  "label": "Office",
  "address_line1": "Floor 3, Business Center",
  "city": "Karachi",
  "postal_code": "74200"
}
```

### Delete Address
```
DELETE /customer/addresses/{id}
```

### Set Default Address
```
PUT /customer/addresses/{id}/default
```

### List Payment Methods
```
GET /customer/payment-methods
```

### Delete Payment Method
```
DELETE /customer/payment-methods/{id}
```

---

## 🏬 VENDOR ROUTES
> Requires: `role:vendor` + Bearer token

### Dashboard
```
GET /vendor/dashboard
```

### Statistics
```
GET /vendor/statistics
```

### Get Vendor Profile
```
GET /vendor/profile
```

### Update Vendor Profile
```
PUT /vendor/profile
```
```json
{
  "company_name": "Khan Enterprises Ltd",
  "company_description": "Premium quality products at affordable prices",
  "contact_phone": "+923009876543",
  "contact_email": "support@khanenterprises.com",
  "website": "https://www.khanenterprises.com",
  "address_line1": "456 Commercial Avenue",
  "city": "Karachi",
  "postal_code": "74200",
  "country_code": "PK"
}
```

### Onboarding Status
```
GET /vendor/profile/onboarding
```

### Update Onboarding Step
```
PUT /vendor/profile/onboarding/step
```
```json
{
  "step": "banking",
  "completed": true
}
```

### List Stores
```
GET /vendor/stores
```

### Create Store
```
POST /vendor/stores
```
```json
{
  "store_name": "Khan Fashion Store",
  "country_code": "PK",
  "currency_code": "PKR",
  "language_code": "en",
  "subdomain": "khan-fashion",
  "description": "Your one-stop shop for trendy fashion items"
}
```

### Get Store
```
GET /vendor/stores/{id}
```

### Update Store
```
PUT /vendor/stores/{id}
```
```json
{
  "store_name": "Khan Fashion & Lifestyle",
  "contact_email": "hello@khanfashion.com",
  "contact_phone": "+923001111222",
  "logo_url": "https://cdn.example.com/logos/khan-fashion.png",
  "banner_url": "https://cdn.example.com/banners/khan-fashion-banner.jpg",
  "primary_color": "#E44D26",
  "secondary_color": "#F16529",
  "description": "Updated store description - trendy fashion and lifestyle",
  "seo_meta_title": "Khan Fashion - Best Clothing in Pakistan",
  "seo_meta_description": "Shop the latest trends in fashion at Khan Fashion Store",
  "facebook_pixel_id": "1234567890123456",
  "google_analytics_id": "G-ABCDEF1234"
}
```

### Delete Store
```
DELETE /vendor/stores/{id}
```

### Activate Store
```
POST /vendor/stores/{id}/activate
```

### Deactivate Store
```
POST /vendor/stores/{id}/deactivate
```

### Add Custom Domain
```
POST /vendor/stores/{id}/domain
```
```json
{
  "domain": "shop.khanfashion.com",
  "is_primary": true
}
```

### Remove Domain
```
DELETE /vendor/stores/{id}/domain/{domainId}
```

### List Products
```
GET /vendor/products?status=active&page=1&per_page=20&search=sneakers&min_price=100&max_price=5000
```

### Create Product
```
POST /vendor/products
```
```json
{
  "vendor_store_id": "store-uuid-here",
  "sku": "KF-SNKR-001",
  "name": "Premium Blue Running Sneakers",
  "description": "High-quality running sneakers with cushioned sole and breathable mesh upper. Perfect for daily runs and gym workouts.",
  "short_description": "Premium running sneakers with comfort-first design",
  "price": 2500.00,
  "special_price": 1999.00,
  "special_price_from": "2025-05-01",
  "special_price_to": "2025-05-31",
  "quantity": 50,
  "weight": 0.8,
  "categories": [
    {"id": 10, "name": "Footwear", "slug": "footwear"},
    {"id": 15, "name": "Sports", "slug": "sports"}
  ],
  "attributes": {
    "color": "Blue",
    "sizes": ["UK6", "UK7", "UK8", "UK9", "UK10"],
    "material": "Mesh + Rubber",
    "brand": "SportFit"
  },
  "media_gallery": [
    {"url": "https://cdn.example.com/products/sneaker-front.jpg", "position": 1, "is_main": true},
    {"url": "https://cdn.example.com/products/sneaker-side.jpg", "position": 2, "is_main": false},
    {"url": "https://cdn.example.com/products/sneaker-back.jpg", "position": 3, "is_main": false}
  ],
  "seo_data": {
    "meta_title": "Premium Blue Running Sneakers - Khan Fashion",
    "meta_description": "Buy premium blue running sneakers online. Best quality, best price.",
    "url_key": "premium-blue-running-sneakers"
  }
}
```

### Get Product
```
GET /vendor/products/{id}
```

### Update Product
```
PUT /vendor/products/{id}
```
```json
{
  "name": "Premium Blue Running Sneakers - Updated",
  "price": 2700.00,
  "special_price": 2199.00,
  "quantity": 45,
  "description": "Updated description with new features highlighted."
}
```

### Delete Product
```
DELETE /vendor/products/{id}
```

### Get Product Drafts
```
GET /vendor/products/drafts
```

### Update Inventory
```
PUT /vendor/products/{id}/inventory
```
```json
{
  "quantity": 75,
  "reason": "New stock received from warehouse"
}
```

### Duplicate Product
```
POST /vendor/products/{id}/duplicate
```

### Bulk Price Update
```
POST /vendor/products/{id}/bulk-price
```
```json
{
  "price": 2800.00,
  "special_price": 2300.00
}
```
> ⚠️ Note: This endpoint returns 501 Not Implemented currently.

### List Vendor Orders
```
GET /vendor/orders?status=processing&payment_status=paid&date_from=2025-01-01&per_page=20
```

### Get Vendor Order Statistics
```
GET /vendor/orders/statistics
```

### Get Order Detail
```
GET /vendor/orders/{id}
```

### Update Order Status
```
PUT /vendor/orders/{id}/status
```
```json
{
  "status": "processing",
  "note": "Order confirmed and being prepared for shipment"
}
```

### Create Shipment
```
POST /vendor/orders/{id}/ship
```
```json
{
  "courier_id": "courier-uuid-here",
  "tracking_number": "TRK1234567890PK",
  "tracking_url": "https://courier.example.com/track/TRK1234567890PK",
  "estimated_delivery": "2025-05-10",
  "notes": "Fragile - handle with care"
}
```

### Generate Invoice
```
POST /vendor/orders/{id}/invoice
```

### Download Invoice
```
GET /vendor/orders/{id}/invoice
```

### List Returns (Vendor)
```
GET /vendor/returns
```

### Get Return (Vendor)
```
GET /vendor/returns/{id}
```

### Approve Return
```
POST /vendor/returns/{id}/approve
```
```json
{
  "notes": "Return approved. Please ship the item back within 7 days."
}
```

### Reject Return
```
POST /vendor/returns/{id}/reject
```
```json
{
  "reason": "The return window has expired as per our policy"
}
```

### Mark Return as Received
```
POST /vendor/returns/{id}/receive
```
```json
{
  "condition": "good",
  "notes": "Item received in original packaging, no damage"
}
```

### Process Refund
```
POST /vendor/returns/{id}/refund
```
```json
{
  "amount": 1999.00,
  "method": "original_payment",
  "notes": "Full refund processed"
}
```

### List Settlements
```
GET /vendor/settlements?page=1&per_page=20
```

### Settlement Summary
```
GET /vendor/settlements/summary
```

### Get Settlement
```
GET /vendor/settlements/{id}
```

### Download Settlement Statement
```
GET /vendor/settlements/{id}/download
```

### List Transactions
```
GET /vendor/transactions?page=1&per_page=20
```

### Export Transactions
```
GET /vendor/transactions/export?format=csv&date_from=2025-01-01&date_to=2025-03-31
```

### List Payouts
```
GET /vendor/payouts
```

### Request Payout
```
POST /vendor/payouts/request
```
```json
{
  "amount": 50000.00,
  "bank_account_id": "bank-account-uuid-here",
  "notes": "Monthly payout request"
}
```

### List Bank Accounts
```
GET /vendor/bank-accounts
```

### Add Bank Account
```
POST /vendor/bank-accounts
```
```json
{
  "account_type": "bank",
  "account_holder_name": "Ali Khan",
  "bank_name": "HBL - Habib Bank Limited",
  "account_number": "01234567890123",
  "iban": "PK36HABB0000001123456702",
  "bic_swift": "HABBPKKA",
  "currency_code": "PKR",
  "is_primary": true
}
```

### Add PayPal Account
```
POST /vendor/bank-accounts
```
```json
{
  "account_type": "paypal",
  "paypal_email": "ali.khan.payments@gmail.com",
  "currency_code": "USD",
  "is_primary": false
}
```

### Verify Bank Account
```
POST /vendor/bank-accounts/{id}/verify
```
```json
{
  "verification_code": "VER-123456"
}
```

### Set Primary Bank Account
```
POST /vendor/bank-accounts/{id}/primary
```

### Delete Bank Account
```
DELETE /vendor/bank-accounts/{id}
```

### Get Shipping Methods
```
GET /vendor/shipping/methods
```

### Update Shipping Methods
```
PUT /vendor/shipping/methods
```
```json
{
  "methods": [
    {
      "id": "standard",
      "enabled": true,
      "price": 150.00,
      "estimated_days": "3-5"
    },
    {
      "id": "express",
      "enabled": true,
      "price": 350.00,
      "estimated_days": "1-2"
    }
  ]
}
```

### Get Shipping Zones
```
GET /vendor/shipping/zones
```

### Create Shipping Zone
```
POST /vendor/shipping/zones
```
```json
{
  "name": "Karachi Local",
  "countries": ["PK"],
  "regions": ["Sindh"],
  "cities": ["Karachi"],
  "rate": 100.00,
  "free_shipping_above": 2000.00
}
```

### Update Shipping Zone
```
PUT /vendor/shipping/zones/{id}
```
```json
{
  "name": "Karachi & Hyderabad",
  "cities": ["Karachi", "Hyderabad"],
  "rate": 120.00,
  "free_shipping_above": 2500.00
}
```

### Delete Shipping Zone
```
DELETE /vendor/shipping/zones/{id}
```

### Sales Report
```
GET /vendor/reports/sales?period=monthly&date_from=2025-01-01&date_to=2025-03-31
```

### Products Report
```
GET /vendor/reports/products?sort_by=revenue&limit=20
```

### Customers Report
```
GET /vendor/reports/customers
```

### Inventory Report
```
GET /vendor/reports/inventory?low_stock_threshold=10
```

### Export Report
```
POST /vendor/reports/export
```
```json
{
  "type": "sales",
  "format": "csv",
  "date_from": "2025-01-01",
  "date_to": "2025-03-31"
}
```

### List Team Members
```
GET /vendor/team
```

### Invite Team Member
```
POST /vendor/team
```
```json
{
  "email": "manager@khanenterprises.com",
  "role": "manager",
  "permissions": ["manage_products", "view_orders", "manage_shipping"]
}
```

### Remove Team Member
```
DELETE /vendor/team/{userId}
```

### Update Team Member Role
```
PUT /vendor/team/{userId}/role
```
```json
{
  "role": "support_agent",
  "permissions": ["view_orders", "manage_returns"]
}
```

### Resend Team Invitation
```
POST /vendor/team/invitations/{invitationId}/resend
```

### List API Keys
```
GET /vendor/api-keys
```

### Create API Key
```
POST /vendor/api-keys
```
```json
{
  "name": "Inventory Management System",
  "permissions": ["read_products", "update_inventory"],
  "expires_at": "2026-12-31"
}
```

### Delete API Key
```
DELETE /vendor/api-keys/{id}
```

### Regenerate API Key
```
POST /vendor/api-keys/{id}/regenerate
```

### List Webhooks
```
GET /vendor/webhooks
```

### Create Webhook
```
POST /vendor/webhooks
```
```json
{
  "url": "https://your-system.com/webhooks/orders",
  "events": ["order.created", "order.status_changed", "payment.received"],
  "secret": "your-webhook-secret-key",
  "is_active": true
}
```

### Update Webhook
```
PUT /vendor/webhooks/{id}
```
```json
{
  "url": "https://your-system.com/webhooks/orders-v2",
  "events": ["order.created", "order.status_changed"],
  "is_active": true
}
```

### Delete Webhook
```
DELETE /vendor/webhooks/{id}
```

### Test Webhook
```
POST /vendor/webhooks/{id}/test
```

---

## 🔧 ADMIN ROUTES
> Requires: `role:admin|super_admin` + Bearer token

### Dashboard
```
GET /admin/dashboard
```

### Statistics
```
GET /admin/statistics
```

### List Users
```
GET /admin/users?page=1&per_page=20&role=customer&status=active
```

### Get User
```
GET /admin/users/{id}
```

### Assign Role
```
PUT /admin/users/{id}/role
```
```json
{
  "role": "vendor"
}
```

### Suspend User
```
PUT /admin/users/{id}/suspend
```
```json
{
  "reason": "Multiple policy violations detected"
}
```

### Activate User
```
PUT /admin/users/{id}/activate
```

### Delete User
```
DELETE /admin/users/{id}
```

### Impersonate User
```
POST /admin/users/{id}/impersonate
```

### List Vendors
```
GET /admin/vendors?status=pending&page=1&per_page=20
```

### Vendor Statistics
```
GET /admin/vendors/statistics
```

### Vendor Applications
```
GET /admin/vendors/applications
```

### Get Vendor
```
GET /admin/vendors/{id}
```

### Approve Vendor
```
POST /admin/vendors/{id}/approve
```
```json
{
  "notes": "KYC verified and all documents are in order"
}
```

### Suspend Vendor
```
POST /admin/vendors/{id}/suspend
```
```json
{
  "reason": "Policy violation - selling counterfeit goods"
}
```

### Activate Vendor
```
POST /admin/vendors/{id}/activate
```

### Update Vendor Plan
```
PUT /admin/vendors/{id}/plan
```
```json
{
  "plan_id": "plan-uuid-here"
}
```

### Verify KYC
```
POST /admin/vendors/{id}/kyc-verify
```
```json
{
  "notes": "All identity documents verified and valid"
}
```

### Reject KYC
```
POST /admin/vendors/{id}/kyc-reject
```
```json
{
  "reason": "National ID document is expired"
}
```

### List Products (Admin)
```
GET /admin/products?status=pending&page=1&per_page=20
```

### Product Statistics
```
GET /admin/products/statistics
```

### Pending Products
```
GET /admin/products/pending
```

### Get Product (Admin)
```
GET /admin/products/{id}
```

### Approve Product Draft
```
POST /admin/products/drafts/{id}/approve
```
```json
{
  "notes": "Product listing is accurate and meets our guidelines"
}
```

### Reject Product Draft
```
POST /admin/products/drafts/{id}/reject
```
```json
{
  "reason": "Product images do not meet quality standards. Please upload high-resolution images (min 1000x1000px)."
}
```

### Request Modification
```
POST /admin/products/drafts/{id}/request-modification
```
```json
{
  "required_changes": "Please update the product description to include material composition and care instructions."
}
```

### Delete Product (Admin)
```
DELETE /admin/products/{id}
```

### Feature Product
```
POST /admin/products/{id}/feature
```

### Unfeature Product
```
POST /admin/products/{id}/unfeature
```

### List Orders (Admin)
```
GET /admin/orders?status=processing&page=1&per_page=20
```

### Order Statistics (Admin)
```
GET /admin/orders/statistics
```

### Get Order (Admin)
```
GET /admin/orders/{id}
```

### Update Order Status (Admin)
```
PUT /admin/orders/{id}/status
```
```json
{
  "status": "refunded",
  "note": "Admin initiated refund due to customer complaint"
}
```

### Process Refund (Admin)
```
POST /admin/orders/{id}/refund
```
```json
{
  "amount": 2500.00,
  "reason": "Product was damaged on arrival",
  "method": "original_payment"
}
```

### Cancel Order (Admin)
```
POST /admin/orders/{id}/cancel
```
```json
{
  "reason": "Vendor unable to fulfill order"
}
```

### List Settlements (Admin)
```
GET /admin/settlements?status=pending&page=1&per_page=20
```

### Pending Settlements
```
GET /admin/settlements/pending
```

### Get Settlement
```
GET /admin/settlements/{id}
```

### Generate Settlement
```
POST /admin/settlements/generate
```
```json
{
  "vendor_id": "vendor-uuid-here",
  "period_from": "2025-04-01",
  "period_to": "2025-04-30"
}
```

### Approve Settlement
```
POST /admin/settlements/{id}/approve
```

### Mark Settlement as Paid
```
POST /admin/settlements/{id}/pay
```
```json
{
  "payment_reference": "TXN-20250430-001",
  "payment_date": "2025-04-30",
  "notes": "Paid via bank transfer"
}
```

### Dispute Settlement
```
POST /admin/settlements/{id}/dispute
```
```json
{
  "reason": "Revenue figures do not match internal records"
}
```

### Download Settlement Statement
```
GET /admin/settlements/{id}/statement
```

### List Coupons
```
GET /admin/coupons
```

### Create Coupon
```
POST /admin/coupons
```
```json
{
  "code": "SAVE20-MAY",
  "type": "percentage",
  "value": 20,
  "minimum_order_amount": 1000.00,
  "maximum_discount": 500.00,
  "usage_limit": 500,
  "usage_limit_per_user": 1,
  "valid_from": "2025-05-01",
  "valid_to": "2025-05-31",
  "is_active": true,
  "applicable_to": "all_products",
  "vendor_ids": []
}
```

### Get Coupon
```
GET /admin/coupons/{id}
```

### Update Coupon
```
PUT /admin/coupons/{id}
```
```json
{
  "usage_limit": 1000,
  "valid_to": "2025-06-15",
  "maximum_discount": 750.00
}
```

### Delete Coupon
```
DELETE /admin/coupons/{id}
```

### Duplicate Coupon
```
POST /admin/coupons/{id}/duplicate
```

### Sync Coupon to Magento
```
POST /admin/coupons/{id}/sync
```

### Get Settings
```
GET /admin/settings
```

### Update Settings
```
PUT /admin/settings
```
```json
{
  "platform_name": "MADD Marketplace",
  "platform_email": "support@madd.eu",
  "default_currency": "PKR",
  "default_language": "en",
  "commission_rate": 5.0,
  "maintenance_mode": false
}
```

### List Countries
```
GET /admin/config/countries
```

### Create Country
```
POST /admin/config/countries
```
```json
{
  "name": "Pakistan",
  "iso2": "PK",
  "iso3": "PAK",
  "phone_code": "+92",
  "currency_code": "PKR",
  "is_active": true
}
```

### Activate Country
```
POST /admin/config/countries/{code}/activate
```

### Create Currency
```
POST /admin/config/currencies
```
```json
{
  "code": "PKR",
  "name": "Pakistani Rupee",
  "symbol": "₨",
  "exchange_rate": 278.50,
  "is_active": true
}
```

### Update Exchange Rate
```
POST /admin/config/currencies/{code}/exchange-rate
```
```json
{
  "rate": 280.00
}
```

### List Plans
```
GET /admin/plans
```

### Create Plan
```
POST /admin/plans
```
```json
{
  "name": "Professional",
  "slug": "professional",
  "price": 4999.00,
  "billing_cycle": "monthly",
  "max_products": 500,
  "max_stores": 3,
  "commission_rate": 4.0,
  "features": {
    "analytics": true,
    "premium_themes": true,
    "api_access": true,
    "priority_support": true,
    "custom_domain": true
  },
  "is_active": true,
  "is_default": false
}
```

### Set Default Plan
```
POST /admin/plans/{id}/set-default
```

### MLM Agents List
```
GET /admin/mlm/agents?page=1&per_page=20
```

### Get MLM Agent
```
GET /admin/mlm/agents/{id}
```

### Verify MLM Agent
```
POST /admin/mlm/agents/{id}/verify
```

### List Commissions
```
GET /admin/mlm/commissions
```

### Process Commissions
```
POST /admin/mlm/commissions/process
```
```json
{
  "period": "2025-04",
  "agent_ids": []
}
```

### Pay Commission
```
POST /admin/mlm/commissions/{id}/pay
```

### MLM Structure
```
GET /admin/mlm/structure
```

### Get MLM Levels
```
GET /admin/mlm/levels
```

### Update MLM Levels
```
PUT /admin/mlm/levels
```
```json
{
  "levels": [
    {"level": 1, "commission_rate": 5.0, "label": "Silver"},
    {"level": 2, "commission_rate": 3.0, "label": "Bronze"},
    {"level": 3, "commission_rate": 1.5, "label": "Associate"}
  ]
}
```

### System Logs
```
GET /admin/system/logs
```

### Get Log by Date
```
GET /admin/system/logs/2025-04-10
```

### Clear Logs
```
DELETE /admin/system/logs
```

### Cache Info
```
GET /admin/system/cache
```

### Clear Cache
```
POST /admin/system/cache/clear
```

### Queue Status
```
GET /admin/system/queues
```

### Retry Failed Job
```
POST /admin/system/queues/retry/{id}
```

### Clear Failed Jobs
```
DELETE /admin/system/queues/failed
```

### Maintenance Status
```
GET /admin/system/maintenance
```

### Toggle Maintenance
```
POST /admin/system/maintenance
```
```json
{
  "enabled": true,
  "message": "We are performing scheduled maintenance. Back in 30 minutes."
}
```

---

## 🤝 MLM AGENT ROUTES
> Requires: `role:mlm_agent` + Bearer token

### Dashboard
```
GET /mlm/dashboard
```

### Team List
```
GET /mlm/team
```

### Team Member Detail
```
GET /mlm/team/{id}
```

### Commissions
```
GET /mlm/commissions
```

### Statistics
```
GET /mlm/statistics
```

### Invite Vendor
```
POST /mlm/invite
```
```json
{
  "email": "newvendor@example.com",
  "name": "Hassan Ahmed",
  "phone": "+923331234567",
  "message": "Join our marketplace and grow your business!"
}
```

### List Invitations
```
GET /mlm/invitations
```

### MLM Structure
```
GET /mlm/structure
```

---

## 🔗 WEBHOOK ROUTES (Signature Verified — No Auth)

### Magento Order Created
```
POST /webhooks/magento/order-created
```
```json
{
  "order_id": 10001,
  "increment_id": "100000001",
  "customer_email": "john@example.com",
  "grand_total": 2499.00,
  "status": "pending",
  "created_at": "2025-04-10T10:00:00Z"
}
```

### Magento Order Updated
```
POST /webhooks/magento/order-updated
```
```json
{
  "order_id": 10001,
  "increment_id": "100000001",
  "status": "processing",
  "updated_at": "2025-04-10T12:00:00Z"
}
```

### Magento Inventory Updated
```
POST /webhooks/magento/inventory-updated
```
```json
{
  "sku": "KF-SNKR-001",
  "qty": 45,
  "is_in_stock": true
}
```

### Stripe Webhook
```
POST /webhooks/stripe
```
```json
{
  "id": "evt_1ABC123",
  "type": "payment_intent.succeeded",
  "data": {
    "object": {
      "id": "pi_1ABC123",
      "amount": 249900,
      "currency": "pkr",
      "status": "succeeded",
      "metadata": {
        "order_id": "order-uuid-here"
      }
    }
  }
}
```

### PayPal Webhook
```
POST /webhooks/paypal
```
```json
{
  "event_type": "PAYMENT.CAPTURE.COMPLETED",
  "resource": {
    "id": "5TY05013RG002845M",
    "amount": {
      "currency_code": "USD",
      "value": "25.00"
    },
    "custom_id": "order-uuid-here",
    "status": "COMPLETED"
  }
}
```

### DHL Carrier Webhook
```
POST /webhooks/carrier/dhl
```
```json
{
  "trackingNumber": "1234567890",
  "status": "in-transit",
  "timestamp": "2025-04-10T14:00:00Z",
  "location": "Karachi Hub",
  "description": "Shipment in transit"
}
```

---

## ⚙️ INTEGRATION ROUTES
> Requires: `X-API-Key: your-api-key` header

### List Orders (Integration)
```
GET /integration/orders?status=processing&page=1
```
Headers:
```
X-API-Key: your-vendor-api-key-here
```

### Get Order (Integration)
```
GET /integration/orders/{id}
```

### Update Order Status (Integration)
```
PUT /integration/orders/{id}/status
```
```json
{
  "status": "shipped",
  "tracking_number": "TRK9876543210",
  "note": "Dispatched from warehouse"
}
```

### Create Shipment (Integration)
```
POST /integration/orders/{id}/ship
```
```json
{
  "carrier": "DHL",
  "tracking_number": "DHL1234567890",
  "tracking_url": "https://www.dhl.com/track/DHL1234567890",
  "shipped_at": "2025-04-10T09:00:00Z"
}
```

### List Products (Integration)
```
GET /integration/products?page=1&per_page=50
```

### Get Product (Integration)
```
GET /integration/products/KF-SNKR-001
```

### Update Inventory (Integration)
```
PUT /integration/products/KF-SNKR-001/inventory
```
```json
{
  "quantity": 30,
  "reason": "Warehouse count adjustment"
}
```

### Update Price (Integration)
```
PUT /integration/products/KF-SNKR-001/price
```
```json
{
  "price": 2600.00,
  "special_price": 2100.00,
  "special_price_from": "2025-05-01",
  "special_price_to": "2025-05-15"
}
```

### List Inventory (Integration)
```
GET /integration/inventory
```

### Batch Inventory Update
```
POST /integration/inventory/batch
```
```json
{
  "items": [
    {"sku": "KF-SNKR-001", "quantity": 30},
    {"sku": "KF-SNKR-002", "quantity": 15},
    {"sku": "KF-SHIRT-005", "quantity": 100}
  ]
}
```

---

## 📝 TESTING TIPS

1. **Order of operations:** Register → Login → Use returned `access_token` in all subsequent requests.
2. **Role-specific tokens:** Log in with different user accounts to test Customer, Vendor, Admin, and MLM Agent routes.
3. **UUIDs:** Most `{id}` parameters are UUIDs. Get them from list endpoint responses first.
4. **Webhook testing:** Add a `X-Webhook-Signature` or `X-Magento-Signature` header with the correct HMAC when testing webhook routes.
5. **Integration API Key:** Pass as header: `X-API-Key: your-key` or `Authorization: Bearer your-key` depending on the `auth:api-key` middleware config.
6. **Placeholder endpoints:** `CustomerAddressController`, `CustomerPaymentController`, and some others extend `PlaceholderApiController` and return 501 — these are not yet implemented.