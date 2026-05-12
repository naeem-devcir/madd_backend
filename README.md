1.Products crud working with magento api
2.Stores working only get from local db

Payloads:

-   -
- PAYLOAD CRUD
-   - POST http://127.0.0.1:8000/api/v1/admin/coupons/

        {
        "code": "TESTCOUPON_5",
        "type": "platform",
        "vendor_id": "7bb5b8ed-1053-4dcd-aeab-7f47c55bda75",
        "discount_type": "percentage",
        "discount_value": 15,
        "min_order_amount": 50,
        "max_uses": 200,
        "per_customer_limit": 2,
        "starts_at": "2024-06-01 00:00:00",
        "expires_at": "2024-12-31 23:59:59",
        "is_active": true,
        "description": "Summer sale 15% off",
        "applicable_to": "all"
        }

-   -
- PRODUCT CRUD
-   - POST http://127.0.0.1:8000/api/v1/admin/products/

        {

"vendor_id": 1,
"vendor_store_id": 2,
"sku": "test-product-new",
"name": "TEst Product",
"description": "The Samsung Galaxy S24 Ultra features a stunning 6.8-inch Dynamic AMOLED 2X display, Snapdragon 8 Gen 3 processor, 12GB RAM, and 256GB storage. Capture professional-quality photos with the 200MP quad-camera system and enjoy all-day battery life with fast charging support. Built with titanium for premium durability and includes the integrated S Pen for productivity and creativity.",
"short_description": "Samsung Galaxy S24 Ultra with 12GB RAM and 256GB storage",
"price": 235.99,
"status": "active",
"special_price": 10.99,
"special_price_from": "2026-05-10",
"special_price_to": "2026-06-10",
"quantity": 40,
"weight": 0.233,
"categories": [4, 7, 9],
"media_gallery": [
{
"url": "https://images.samsung.com/is/image/samsung/p6pim/pk/2401/gallery/pk-galaxy-s24-s928-sm-s928bzkgpkd-thumb-539573285"
},
{
"url": "https://fdn.gsmarena.com/imgroot/reviews/24/samsung-galaxy-s24-ultra/-1220x526/gsmarena_001.jpg"
}
],
"seo_data": {
"meta_title": "Samsung Galaxy S24 Ultra 256GB | Best Price & Features 2026",
"meta_description": "Buy Samsung Galaxy S24 Ultra with Snapdragon 8 Gen 3, 200MP camera, 12GB RAM, and 256GB storage. Explore latest deals and specifications.",
"keywords": [
"Samsung Galaxy S24 Ultra",
"Samsung phone",
"Snapdragon 8 Gen 3",
"Android flagship",
"200MP camera phone",
"Samsung S24 Ultra"
]
}
}

-   -
- CATEGORY CRUD
-   -

http://127.0.0.1:8000/api/v1/admin/vendors/1/categories/
{
"name": "Premium Electronics",
"is_active": true,
"include_in_menu": true,
"position": 1,
"url_key": "premium-electronics",
"description": "Premium electronics with extended warranty",
"meta_title": "Premium Electronics Store",
"meta_description": "Best premium electronics deals",
"parent_id": null
}

-   -
- CMSBLOCK CRUD
-   -

GET ALL CMSBLOCKS
GET http://127.0.0.1:8000/api/v1/admin/vendors/7{vendorUuid}/cms-blocks/{ccae08e3-4943-458d-85be-714fda3304a1}

GET BY UUID CMSBLOCK
GET http://127.0.0.1:8000/api/v1/admin/vendors/7bb5b8ed-1053-4dcd-aeab-7f47c55bda75/cms-blocks/ccae08e3-4943-458d-85be-714fda3304a1

GET BY IDENTIFIER CMSBLOCK
http://127.0.0.1:8000/api/v1/admin/vendors/7{vendorUuid}/cms-blocks/by-identifier/{identifier}

http://127.0.0.1:8000/api/v1/admin/vendors/{vendorUuid}/cms-blocks
{
"identifier": "homepage-banner",
"title": "Homepage Banner",
"content": "<div>Welcome</div>",
"active": true
}

POST http://127.0.0.1:8000/api/v1/admin/vendors/{vendorUuid}/cms-blocks

{
"identifier": "homepage-banner",
"title": "Homepage Banner",
"content": "<div>Welcome</div>",
"active": true
}

PUT http://127.0.0.1:8000/api/v1/admin/vendors/{vendorUuid}/cms-blocks/{blockUuid}

{
"identifier": "homepage-banner-updated",
"title": "Homepage Banner Updated",
"content": "<div>Welcome Again</div>",
"active": true
}

Delete http://127.0.0.1:8000/api/v1/admin/vendors/{vendorUuid}/cms-blocks/{blockUuid}


-   -
- CMSPAGE CRUD
-   -

GET ALL
GET http://127.0.0.1:8000/api/v1/admin/vendors/{vendorUuid}/cms-pages/


GET BY UUID
GET http://127.0.0.1:8000/api/v1/admin/vendors/{vendorUuid}/cms-pages/{cmspageUuid}

GET BY identifier
http://127.0.0.1:8000/api/v1/admin/vendors/{vendorUuid}/cms-pages/by-identifier/{identifier}


create cms page
POST http://127.0.0.1:8000/api/v1/admin/vendors/{vendorUuid}/cms-pages/
{
    "identifier": "case-studies",
    "title": "Case Studies",
    "content": "<h1>Our Case Studies</h1><p>We are the best...</p>",
    "page_layout": "1column",
    "content_heading": "Welcome Case Studies",
    "is_active": true,
    "sort_order": 1,
    "meta_title": "Our Case Studies",
    "meta_keywords": "case studies, portfolio, projects",
    "meta_description": "Learn more about our projects"
}


Update the cmspage
PUT http://127.0.0.1:8000/api/v1/admin/vendors/{vendorUuid}/cms-pages/{cmspageUuid}
{
    "identifier": "case-studies-updated",
    "title": "Case Studies updated",
    "content": "<h1>Our Case Studies</h1><p>We are the best...</p>",
    "page_layout": "1column",
    "content_heading": "Welcome Case Studies updated",
    "is_active": true,
    "sort_order": 1,
    "meta_title": "Our Case Studies",
    "meta_keywords": "case studies, portfolio, projects",
    "meta_description": "Learn more about our projects"
}