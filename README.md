# TechKart Unified Backend API

A scalable, secure Core PHP REST API backend powering both:
1. **TechKart Customer App** (Flutter E-Commerce)
2. **TechKart Buddy App** (Flutter Seller & Admin Management)

---

## 🛠 Technology Stack
- **Language**: Core PHP 8.2+
- **Database**: MySQL 8.0+ with PDO (`utf8mb4`)
- **Architecture**: PSR-4 autoloading, MVC-inspired clean separation (Controllers, Models, Services, Middlewares)
- **API Spec**: RESTful JSON responses (`/api/v1/...`)
- **Authentication**: JWT / Bearer token security

---

## 📁 Project Directory Structure

```
techkart_backend/
├── app/
│   ├── Controllers/
│   │   ├── Customer/           # Endpoints for TechKart Customer app
│   │   │   └── CustomerAuthController.php
│   │   ├── Buddy/              # Endpoints for TechKart Buddy seller/admin app
│   │   │   └── BuddyAuthController.php
│   │   └── HealthController.php # System health & database connection checks
│   ├── Core/                   # Foundational framework utilities
│   │   ├── Database.php        # PDO Database connection singleton
│   │   ├── Env.php             # Lightweight .env parser
│   │   ├── Request.php         # Request wrapper (JSON input, headers, query params)
│   │   ├── Response.php        # Standardized JSON response handler
│   │   └── Router.php          # REST router with route parameter support
│   ├── Middlewares/            # Authentication, CORS, and validation middlewares
│   │   ├── AuthMiddleware.php
│   │   └── CorsMiddleware.php
│   ├── Models/                 # Database entity queries & data access
│   └── Services/               # Business logic & 3rd party integrations
├── bootstrap/
│   └── app.php                 # App initialization, PSR-4 autoloader & error handler
├── database/
│   └── migrations/
│       └── 001_initial_schema.sql # Core MySQL schema (Users, Roles, Products, Orders)
├── public/
│   ├── .htaccess               # URL rewrite for Apache
│   └── index.php               # Front controller / single entry point
├── routes/
│   └── api_v1.php              # Versioned API route definitions (/api/v1/...)
├── .env.example                # Sample environment configuration template
├── .env                        # Local environment configuration (do NOT commit secrets)
├── .gitignore
├── .htaccess                   # Root rewrite rule routing requests to public/
└── README.md
```

---

## 🚀 Setup & Installation

### 1. Requirements
- PHP 8.2 or higher (with `pdo`, `pdo_mysql`, `json`, `mbstring` enabled)
- MySQL 8.0+ (or MariaDB 10.5+)
- Web server (Apache, Nginx, or PHP Built-in Server)

### 2. Environment Configuration
Copy `.env.example` to `.env` if not already created:
```bash
cp .env.example .env
```
Update your database credentials in `.env`:
```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=techkart_db
DB_USER=root
DB_PASS=your_password
```

### 3. Database Migration
Import the initial schema using MySQL CLI or phpMyAdmin:
```bash
mysql -u root -p techkart_db < database/migrations/001_initial_schema.sql
```

### 4. Running the Development Server
You can start the PHP built-in server directly from the backend directory:
```bash
php -S 127.0.0.1:8000 -t public
```

---

## 📡 API Endpoints Documentation

All requests and responses use `application/json; charset=UTF-8`.

### 1. Health Verification
- **Endpoint**: `GET /api/v1/health`
- **Auth**: None
- **Response**: Returns backend service version and database connectivity status.

---

### 2. Customer Authentication (TechKart Customer App)

#### `POST /api/v1/customer/auth/register`
Creates a new customer account and returns an authentication Bearer token.
- **Request Body**:
```json
{
  "full_name": "Rahul Sharma",
  "email": "rahul.test@techkart.com",
  "password": "SecretPassword123",
  "phone": "9876543210"
}
```
- **Response `201 Created`**:
```json
{
  "success": true,
  "status": 201,
  "message": "Customer account registered successfully",
  "data": {
    "user": {
      "id": 1,
      "role": "customer",
      "full_name": "Rahul Sharma",
      "email": "rahul.test@techkart.com",
      "phone": "9876543210",
      "avatar_url": null
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
    "token_type": "Bearer"
  }
}
```

#### `POST /api/v1/customer/auth/login`
Authenticates an existing customer. Accounts with role `seller` or `admin` are rejected.
- **Request Body**:
```json
{
  "email": "rahul.test@techkart.com",
  "password": "SecretPassword123"
}
```
- **Response `200 OK`**: Returns user profile and signed JWT token.

#### `GET /api/v1/customer/auth/profile`
Retrieves current authenticated customer profile.
- **Headers**: `Authorization: Bearer <token>`
- **Access Guard**: Strictly limited to `role: customer`.

---

### 3. Buddy Authentication (TechKart Buddy Seller / Admin App)

#### `POST /api/v1/buddy/auth/register-seller`
Allows a seller to register their account and store profile atomically.
- **Request Body**:
```json
{
  "full_name": "Vikram Patel",
  "email": "vikram.store@techkart.com",
  "password": "SellerPassword123",
  "store_name": "Apex Electronics & Gadgets",
  "phone": "9822334455",
  "gst_number": "27AABCT3518Q1ZP",
  "city": "Mumbai",
  "state": "Maharashtra",
  "pincode": "400001"
}
```
- **Response `201 Created`**: Creates records in `users` and `seller_profiles`, returning token and store data.

#### `POST /api/v1/buddy/auth/login`
Authenticates a seller or admin. Rejects accounts with `role: customer` (`403 Forbidden`).
- **Request Body**:
```json
{
  "email": "vikram.store@techkart.com",
  "password": "SellerPassword123"
}
```
- **Response `200 OK`**: Returns user profile, store profile (for sellers), and Bearer token.

#### `GET /api/v1/buddy/auth/profile`
Retrieves current authenticated seller/admin profile with store information.
- **Headers**: `Authorization: Bearer <token>`
- **Access Guard**: Limited to `role: seller` or `role: admin`. Attempting access with a customer token returns `403 Forbidden`.

---

### 4. Public Product Catalog (TechKart Customer App)

#### `GET /api/v1/customer/categories`
Lists all active catalog categories for the store.
- **Auth**: None (Public)
- **Response `200 OK`**: Array of categories (`id`, `parent_id`, `name`, `slug`, `image_url`).

#### `GET /api/v1/customer/products`
Lists published products with search, multi-factor filtering, sorting, and pagination. Only products with `status = 'published'` and from active sellers are returned.
- **Auth**: None (Public)
- **Query Parameters**:
  - `search`: Search query string matched against title and description.
  - `category_id`: Filter by specific category ID.
  - `min_price` & `max_price`: Numeric price range boundaries.
  - `in_stock`: `1` to filter items with `stock_quantity > 0`.
  - `sort_by`: `latest` (default), `price_asc`, `price_desc`, `title_asc`, `title_desc`, `oldest`.
  - `page`: Page number (default: `1`).
  - `limit`: Items per page (default: `20`, max `100`).
- **Response `200 OK`**:
```json
{
  "success": true,
  "status": 200,
  "message": "Products retrieved successfully",
  "data": [
    {
      "id": 1,
      "seller_id": 2,
      "category_id": 3,
      "title": "Wireless Noise-Cancelling Headphones Pro",
      "slug": "wireless-noise-cancelling-headphones-pro",
      "description": "Flagship headphones with 40-hour battery.",
      "price": 14999.00,
      "mrp": 19999.00,
      "stock_quantity": 25,
      "status": "published",
      "category_name": "Audio",
      "seller_store_name": "Apex Electronics & Gadgets",
      "primary_image_url": "https://...",
      "images": [
        { "image_url": "https://...", "is_primary": true, "sort_order": 0 }
      ]
    }
  ],
  "pagination": {
    "total": 1,
    "page": 1,
    "limit": 20,
    "total_pages": 1,
    "has_next": false,
    "has_previous": false
  }
}
```

#### `GET /api/v1/customer/products/{id}`
Returns full product details, category, seller store details, and complete image gallery.
- **Auth**: None (Public)
- **Response `200 OK`**: Full product object with detailed `images` array. Returns `404 Not Found` if product is in `draft` or `archived` status.

---

### 5. Customer Shopping Cart (TechKart Customer App)

Protected by `AuthMiddleware(['customer'])`. Only accounts with `role: customer` can view and manipulate their personal shopping cart. Clients cannot tamper with prices; item pricing, discounts, and order subtotals are computed dynamically from the database.

#### `GET /api/v1/customer/cart`
Retrieves the authenticated customer's full cart with dynamic server-calculated pricing, seller ownership metadata, available stock status, and cart totals.
- **Headers**: `Authorization: Bearer <customer_token>`
- **Response `200 OK`**:
```json
{
  "success": true,
  "status": 200,
  "message": "Cart retrieved successfully",
  "data": {
    "items": [
      {
        "cart_item_id": 1,
        "product_id": 2,
        "seller_id": 2,
        "seller_store_name": "Apex Electronics & Gadgets",
        "category_name": "Electronics",
        "title": "Wireless Noise-Cancelling Headphones Pro",
        "slug": "wireless-noise-cancelling-headphones-pro",
        "primary_image_url": "https://...",
        "unit_price": 14999.00,
        "mrp": 19999.00,
        "quantity": 2,
        "line_total": 29998.00,
        "stock_quantity": 25,
        "is_in_stock": true,
        "is_available": true,
        "is_quantity_available": true,
        "added_at": "2026-10-26 14:20:00"
      }
    ],
    "summary": {
      "total_unique_items": 1,
      "total_quantity": 2,
      "subtotal": 29998.00,
      "total_mrp": 39998.00,
      "total_discount": 10000.00,
      "currency": "INR",
      "has_unavailable_items": false
    }
  }
}
```

#### `POST /api/v1/customer/cart`
Adds an item to the cart. If the product already exists in the customer's cart, its quantity is incremented (preventing duplicate rows).
- **Headers**: `Authorization: Bearer <customer_token>`
- **Request Body**:
```json
{
  "product_id": 2,
  "quantity": 1
}
```
- **Validation & Business Rules**:
  - Validates product existence, `published` status, active seller account, and available stock.
  - Client-submitted prices are ignored; database price is strictly enforced.
  - Returns `400 Bad Request` if item is out of stock or requested quantity exceeds available inventory.
- **Response `201 Created`**: Returns updated cart with recalculated items and summary.

#### `PUT` or `PATCH /api/v1/customer/cart/{id}`
Updates quantity for an existing cart item. `{id}` can be either the `cart_item_id` or the `product_id`.
- **Headers**: `Authorization: Bearer <customer_token>`
- **Request Body**:
```json
{
  "quantity": 3
}
```
- **Rules**:
  - Setting `quantity: 0` automatically removes the item from the cart.
  - Validates stock limits against current database inventory.
- **Response `200 OK`**: Returns updated cart and recalculated totals.

#### `DELETE /api/v1/customer/cart/{id}`
Removes an individual product line from the cart. `{id}` can be either the `cart_item_id` or the `product_id`.
- **Headers**: `Authorization: Bearer <customer_token>`
- **Response `200 OK`**: Returns updated cart.

#### `DELETE /api/v1/customer/cart`
Clears all items from the customer's shopping cart.
- **Headers**: `Authorization: Bearer <customer_token>`
- **Response `200 OK`**: Returns empty cart summary.

---

### 6. Customer Delivery Addresses (TechKart Customer App)

Protected by `AuthMiddleware(['customer'])`. Manages saved delivery locations matching Flutter UI screens **SCR-26**, **SCR-27**, and **SCR-39**.

#### `GET /api/v1/customer/addresses`
Lists all saved addresses for the authenticated customer ordered by default first.
- **Headers**: `Authorization: Bearer <customer_token>`

#### `POST /api/v1/customer/addresses`
Adds a new delivery address to the customer's address book.
- **Headers**: `Authorization: Bearer <customer_token>`
- **Request Body**:
```json
{
  "label": "Home",
  "recipient_name": "Rahul Sharma",
  "phone": "+8801712345678",
  "address_line1": "House 42, Road 11, Block D, Banani",
  "address_line2": "Floor 4A",
  "city": "Dhaka",
  "state": "Dhaka Division",
  "postal_code": "1213",
  "country": "Bangladesh",
  "delivery_instructions": "Call before arrival",
  "is_default": true
}
```

#### `PUT` or `PATCH /api/v1/customer/addresses/{id}`
Updates an existing address. Setting `is_default: true` clears the default flag on all other addresses.

#### `DELETE /api/v1/customer/addresses/{id}`
Removes an address from the customer's address book.

---

### 7. Customer Checkout & Orders (TechKart Customer App)

Protected by `AuthMiddleware(['customer'])`. Powers **SCR-28** (Order Review), **SCR-31** (Order Confirmation), **SCR-32** (Order History), and **SCR-33** (Order Details).

#### `GET /api/v1/customer/checkout/preview`
Pre-calculates live delivery destination, items breakdown, unit prices, shipping fees, discounts, and total payable amount before placing an order.
- **Headers**: `Authorization: Bearer <customer_token>`
- **Query Parameters**: `address_id` (optional; defaults to default address)

#### `POST /api/v1/customer/orders`
Places an order from active cart items inside an atomic PDO transaction with inventory stock decrements and price freezing. Supports **idempotency** via `Idempotency-Key` header.
- **Headers**:
  - `Authorization: Bearer <customer_token>`
  - `Idempotency-Key: <unique_client_uuid_or_key>` *(Recommended to prevent duplicate charging / double order placement)*
- **Request Body**:
```json
{
  "address_id": 1,
  "payment_method": "card",
  "notes": "Handle with care"
}
```
- **Response `201 Created`**:
```json
{
  "success": true,
  "status": 201,
  "message": "Order placed successfully",
  "data": {
    "order_id": 1,
    "order_number": "TK-202610-8492A1",
    "total_amount": 29998.00,
    "total_quantity": 2,
    "payment_status": "pending",
    "order_status": "placed",
    "payment_method": "card",
    "transaction_reference": "TXN-A1B2C3D4E5F6",
    "shipping_address": { ... },
    "items_count": 1,
    "payment_intent": {
      "transaction_reference": "TXN-A1B2C3D4E5F6",
      "amount": 29998.00,
      "currency": "INR",
      "gateway": "simulated_gateway",
      "verify_endpoint": "/api/v1/customer/payments/verify"
    }
  }
}
```

#### `GET /api/v1/customer/orders`
Retrieves customer order history with pagination and status filtering (`placed`, `processing`, `shipped`, `delivered`, `cancelled`).
- **Headers**: `Authorization: Bearer <customer_token>`
- **Query Parameters**: `status` (`all` default), `page`, `limit`

#### `GET /api/v1/customer/orders/{id}`
Retrieves full order invoice, immutable price freeze snapshot, line item thumbnails, seller store information, payment log, and cancellation eligibility flag.
- **Headers**: `Authorization: Bearer <customer_token>`
- **URL Parameter**: `{id}` can be order ID or order reference string (e.g. `TK-202610-8492A1`).

#### `POST /api/v1/customer/orders/{id}/cancel`
Cancels an order if in `placed` or `processing` status. Restores product inventory atomically and updates payment status to `refunded` if previously paid.
- **Headers**: `Authorization: Bearer <customer_token>`
- **Request Body**: `{ "reason": "Customer cancellation reason" }`

---

### 8. Payments (TechKart Customer App & Gateway)

Powers **SCR-29** (Payment Gateway Webview/SDK) and **SCR-30** (Payment Verification Screen).

#### `POST /api/v1/customer/payments/initiate`
Generates payment intent session, client secrets, and gateway references for card, UPI, netbanking, and bKash channels.
- **Headers**: `Authorization: Bearer <customer_token>`
- **Request Body**:
```json
{
  "order_id": "TK-202610-8492A1",
  "payment_method": "card"
}
```

#### `POST /api/v1/customer/payments/verify`
Idempotently verifies transaction completion from the Flutter client after gateway return callback. Atomically updates payment record to `paid` and order status to `processing`. (Simulated verification strictly enabled in development environments).
- **Headers**:
  - `Authorization: Bearer <customer_token>`
  - `Idempotency-Key: <unique_client_uuid_or_key>`
- **Request Body**:
```json
{
  "transaction_reference": "TXN-A1B2C3D4E5F6",
  "gateway_payment_ref": "ch_3Pk4...",
  "status": "success"
}
```

---

### 9. Seller & Admin Order Fulfillment (TechKart Buddy App)

Protected by `AuthMiddleware(['seller', 'admin'])`. Powers **TechKart Buddy Orders Management** screens (`marketplace_order_management.dart` & `seller/orders_details.dart`).

#### `GET /api/v1/buddy/orders`
Lists orders. For sellers, strictly isolates orders containing their products and returns seller-specific item subtotals. For admins, provides marketplace-wide order oversight.
- **Headers**: `Authorization: Bearer <seller_or_admin_token>`
- **Query Parameters**: `status` (`all` default), `page`, `limit`

#### `GET /api/v1/buddy/orders/{id}`
Returns order details filtered to the authenticated seller's store items or complete details for administrators.
- **Headers**: `Authorization: Bearer <seller_or_admin_token>`

#### `PATCH` or `PUT /api/v1/buddy/orders/{id}/status`
Updates order item status (`placed`, `processing`, `shipped`, `delivered`, `cancelled`). Automatically synchronizes the overall order status once all line items reach fulfillment.
- **Headers**: `Authorization: Bearer <seller_or_admin_token>`
- **Request Body**:
```json
{
  "status": "shipped",
  "item_id": 1
}
```

---

### 10. Seller Product Management (TechKart Buddy App)

Protected by `AuthMiddleware(['seller', 'admin'])`. A seller can only view, update, and delete their own products.

#### `GET /api/v1/buddy/products`
Lists the authenticated seller's products (including `draft`, `published`, and `archived`).
- **Headers**: `Authorization: Bearer <token>`
- **Query Parameters**: `status` (`draft`|`published`|`archived`), `search`, `page`, `limit`.

#### `POST /api/v1/buddy/products`
Creates a new product for the authenticated seller.
- **Headers**: `Authorization: Bearer <token>`
- **Admin Usage**: When called by an administrator (`role: admin`), a valid `seller_id` must be passed in the payload or query. Omission or specifying a non-existent seller returns `422 Unprocessable Entity`.
- **Request Body**:
```json
{
  "category_id": 3,
  "seller_id": 2,
  "title": "Mechanical RGB Gaming Keyboard",
  "description": "Tactile blue switches with programmable macro keys.",
  "price": 4500.00,
  "mrp": 5999.00,
  "stock_quantity": 40,
  "status": "published",
  "images": [
    { "image_url": "https://...", "is_primary": true },
    { "image_url": "https://...", "is_primary": false }
  ]
}
```
- **Response `201 Created`**: Returns created product ID and generated URL slug.

#### `GET /api/v1/buddy/products/{id}`
Retrieves seller product details by ID (enforces ownership).

#### `PUT` or `PATCH /api/v1/buddy/products/{id}`
Updates product attributes (`title`, `price`, `mrp`, `stock_quantity`, `status`, `images`).
- **Headers**: `Authorization: Bearer <token>`
- **Validation**:
  - Sellers can only modify their own products (`404 Not Found or access denied` for other sellers).
  - Cross-validates final price against existing or updated MRP. If an update causes selling price to exceed MRP, or MRP to drop below selling price, the request is rejected with `422 Unprocessable Entity` (`MRP cannot be less than selling price.`).

#### `DELETE /api/v1/buddy/products/{id}`
Deletes a product owned by the seller (cascading deletes related images).
- **Headers**: `Authorization: Bearer <token>`

---

## 🛡 Security & Authentication Architecture
- **Expiring JWT Tokens**: Tokens are generated via HMAC-SHA256 with configurable time-to-live (`JWT_EXPIRY` in `.env`).
- **Password Protection**: Passwords are hashed using `PASSWORD_BCRYPT` with cost 12. Password hashes and secrets are never returned in responses.
- **Strict Role Separation**: Route-level middleware enforces access constraints (`customer` vs. `seller`/`admin`), preventing cross-app privilege escalation.
- **Prepared Statements**: All queries use PDO parameter binding to prevent SQL injection.
- **Header Parsing**: Robust Authorization header resolution across Apache, CLI built-in server, and FastCGI environments.

---

## 🧪 Verified Test Results

| Test Suite | Assertions Passed | Status | Coverage |
|---|---|---|---|
| `test_product_catalog.php` | **21 / 21** (100%) | **PASS** | Categories, Keyword Search, Price/Stock Filters, Slugs, Draft Isolation, Seller CRUD, Admin `seller_id` Validation, Price vs. MRP Cross-Validation, Role Ownership Isolation |
| `test_cart_api.php` | **13 / 13** (100%) | **PASS** | Role Guards, Empty Cart, Stock Checks, Server-side Price Calculations, Quantity Increments, Cart Clears |
