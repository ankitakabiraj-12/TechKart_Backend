# 🛒 TechKart Unified Backend API

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![JWT](https://img.shields.io/badge/JWT-HS256%20Pure--PHP-000000?style=for-the-badge&logo=json-web-tokens&logoColor=white)](https://jwt.io/)
[![Architecture](https://img.shields.io/badge/Architecture-Clean%20MVC%20%7C%20REST-brightgreen?style=for-the-badge)]()
[![Gemini AI](https://img.shields.io/badge/Google%20Gemini-Multimodal%20AI-4285F4?style=for-the-badge&logo=google&logoColor=white)](https://ai.google.dev/)

A production-grade, highly scalable, and modular pure-PHP RESTful backend engineered to power the entire **TechKart Multi-Vendor E-Commerce Ecosystem**:
1. **TechKart Customer App** (Flutter Mobile Client: Browse, Search, Cart, Wishlist, Checkout, Tracking, Returns, AI Assistant)
2. **TechKart Buddy App** (Flutter Merchant & Admin Portal: Store & Catalog Management, Orders, Live Logistics, Low Stock Alerts, Customer Moderation, Settlements, Platform Analytics)

---

## 📑 Table of Contents

- [Architectural Overview](#-architectural-overview)
- [Key Features](#-key-features)
- [Technology Stack](#-technology-stack)
- [Directory Structure](#-directory-structure)
- [Security & Hardening](#-security--hardening)
- [Installation & Local Setup](#-installation--local-setup)
- [Database Schema & Migrations](#-database-schema--migrations)
- [Comprehensive API Specification](#-comprehensive-api-specification)
  - [1. System Health](#1-system-health)
  - [2. Customer Authentication & Account Security](#2-customer-authentication--account-security)
  - [3. Buddy Authentication (Sellers & Administrators)](#3-buddy-authentication-sellers--administrators)
  - [4. Customer Catalog & Category Discovery](#4-customer-catalog--category-discovery)
  - [5. Shopping Cart & Inventory Validation](#5-shopping-cart--inventory-validation)
  - [6. Customer Wishlist](#6-customer-wishlist)
  - [7. Delivery Addresses](#7-delivery-addresses)
  - [8. Checkout & Order Placement](#8-checkout--order-placement)
  - [9. Payments & Payment Verification](#9-payments--payment-verification)
  - [10. Live Order Tracking & Logistics](#10-live-order-tracking--logistics)
  - [11. Customer Product Reviews & Ratings](#11-customer-product-reviews--ratings)
  - [12. Customer Returns & Refunds](#12-customer-returns--refunds)
  - [13. AI Multimodal Visual Search & Chat Assistant](#13-ai-multimodal-visual-search--chat-assistant)
  - [14. Buddy Merchant Store & Profile](#14-buddy-merchant-store--profile)
  - [15. Buddy Merchant Catalog & Product Approval Lifecycle](#15-buddy-merchant-catalog--product-approval-lifecycle)
  - [16. Buddy Inventory Control & Low Stock Alerts](#16-buddy-inventory-control--low-stock-alerts)
  - [17. Buddy Order Fulfillment & Shipping](#17-buddy-order-fulfillment--shipping)
  - [18. Buddy Returns Decision & Reverse Logistics](#18-buddy-returns-decision--reverse-logistics)
  - [19. Buddy Merchant & Admin Review Moderation](#19-buddy-merchant--admin-review-moderation)
  - [20. Buddy Analytics, Revenue Reports & Financial Settlements](#20-buddy-analytics-revenue-reports--financial-settlements)
  - [21. Buddy Administrative Controls (Customers, Categories, Audit Logs, Settings)](#21-buddy-administrative-controls)
- [Automated Testing & Verification](#-automated-testing--verification)
- [Environment Configuration (`.env`)](#-environment-configuration-env)
- [License & Authors](#-license--authors)

---

## 🏛 Architectural Overview

```
                      ┌───────────────────────────────────────────────┐
                      │             Clients (Flutter Apps)            │
                      │  TechKart Customer App  │  TechKart Buddy App │
                      └───────────────────────┬───────────────────────┘
                                              │ HTTP / JSON
                                              ▼
                      ┌───────────────────────────────────────────────┐
                      │              Public Front Controller          │
                      │               public/index.php                │
                      └───────────────────────┬───────────────────────┘
                                              │
                      ┌───────────────────────▼───────────────────────┐
                      │         App Bootstrap (bootstrap/app.php)     │
                      │  • PSR-4 Autoloading                          │
                      │  • .env Parsing (App\Core\Env)                │
                      │  • Hardened Global Exception Handler          │
                      └───────────────────────┬───────────────────────┘
                                              │
                      ┌───────────────────────▼───────────────────────┐
                      │              Router & Middlewares             │
                      │  • CorsMiddleware (Preflight / Origins)       │
                      │  • Router (Route parameter extraction)        │
                      │  • AuthMiddleware (Role-based access control) │
                      └───────┬───────────────────────────────┬───────┘
                              │                               │
            ┌─────────────────▼─────────────┐   ┌─────────────▼─────────────────┐
            │   Customer Controllers        │   │     Buddy Controllers         │
            │ • CustomerAuthController      │   │ • BuddyAuthController         │
            │ • CustomerCatalogController   │   │ • BuddyProductController      │
            │ • CustomerCartController      │   │ • BuddyInventoryController    │
            │ • CustomerOrderController     │   │ • BuddyOrderController        │
            │ • CustomerPaymentController   │   │ • BuddyReturnController       │
            │ • CustomerReturnController    │   │ • BuddyDashboardController    │
            │ • CustomerAiController        │   │ • BuddyReviewController       │
            └───────────────┬───────────────┘   └─────────────┬─────────────────┘
                            │                                 │
                            └────────────────┬────────────────┘
                                             │
                      ┌──────────────────────▼────────────────────────┐
                      │                 Core & Services               │
                      │  • Database (PDO Singleton / Transactions)    │
                      │  • Jwt (Strict HS256 / Expirations / Rotation)│
                      │  • GeminiApiService (Visual Search & Chat)    │
                      │  • MailService (SMTP OTPs & Password Resets)  │
                      └──────────────────────┬────────────────────────┘
                                             │
                      ┌──────────────────────▼────────────────────────┐
                      │              Database Layer (MySQL 8)         │
                      │  Users • Orders • Payments • Products • Cart  │
                      │  Shipments • Reviews • Returns • Settlements  │
                      └───────────────────────────────────────────────┘
```

- **Pure PHP Engine**: Built without bulky framework overhead, achieving microsecond response times and minimal memory footprint.
- **Strict Separation of Concerns**: Route handling, middleware guards, business workflows, and data persistence are decoupled across clean PSR-4 namespaces.
- **Fail-Safe Security**: Strict token hashing, server-side price recalculation, atomic database transactions with row-level locks (`FOR UPDATE`), and hardened exception handling.

---

## ✨ Key Features

- **Multi-Role RBAC**: Independent user scopes for `customer`, `seller`, and `admin` with strict guardrails preventing privilege escalation.
- **Bulletproof JWT Authentication**: Pure-PHP HS256 implementation with mandatory server secrets, token expiry (`iat`/`exp`), database blacklist revocation, and cryptographic refresh token rotation.
- **Price-Freeze & Atomic Checkout**: Customer cart checkout enforces server-side database pricing, performs atomic inventory deduction, and creates price freeze snapshots preventing manipulation.
- **Idempotency Protection**: Integrated idempotency key engine for order placement and payment verification, eliminating double charging or duplicate order creation.
- **Multimodal AI Integration**: Powered by Google Gemini (`gemini-3.5-flash-lite`), providing visual product identification, attribute extraction, and multilingual (English & Bengali) e-commerce chat assistance with strict SSL peer verification.
- **End-to-End Logistics Tracking**: Live milestone timeline (`placed` ➔ `processing` ➔ `shipped` ➔ `out_for_delivery` ➔ `delivered`) with AWB courier bindings and location checkpoints.
- **Reverse Logistics & Refunds**: Customer return request lifecycle with seller/admin quality inspection approval, reason tracking, and automated refund ledger recording.
- **Complete Merchant & Admin Suite**: Product submission with admin approval workflows, low stock warning thresholds, automated merchant payout settlements, and comprehensive audit logging.

---

## 🛠 Technology Stack

| Component | Technology | Description |
|---|---|---|
| **Runtime** | PHP 8.2+ | Modern PHP with strict types (`declare(strict_types=1);`), enums, and match expressions |
| **Database** | MySQL 8.0+ / MariaDB 10.5+ | Full relational engine with InnoDB, foreign keys, row-level locking, and `utf8mb4` encoding |
| **Authentication** | JWT & Refresh Tokens | HMAC-SHA256 tokens with server-side blacklist and rotating 30-day refresh tokens |
| **Password Hashing** | Bcrypt (`PASSWORD_BCRYPT`) | Cost factor 12 cryptographic password hashing |
| **AI / Multimodal** | Google Gemini API | Multimodal vision analysis and AI chat assistance |
| **Mailer** | SMTP (Socket/TLS) | Pure-PHP socket SMTP transport for OTP verification and password reset notifications |
| **Web Server** | Apache / Nginx / Built-in | Optimized `.htaccess` front-controller rewrite rules |

---

## 📁 Directory Structure

```
techkart_backend/
├── app/
│   ├── Controllers/
│   │   ├── Buddy/                  # TechKart Buddy Seller & Admin Controllers
│   │   │   ├── BuddyAuthController.php        # Merchant/Admin registration, login, profile
│   │   │   ├── BuddyCategoryController.php    # Category & specification template management
│   │   │   ├── BuddyCustomerController.php    # Customer directory & account status control
│   │   │   ├── BuddyDashboardController.php   # Revenue statistics, settlements, audit logs
│   │   │   ├── BuddyInventoryController.php   # Stock levels, batch adjustments, low-stock alerts
│   │   │   ├── BuddyOrderController.php       # Order fulfillment, item status, dispatch
│   │   │   ├── BuddyProductController.php     # Product CRUD, approval submission, variant images
│   │   │   ├── BuddyProfileController.php     # Store details, GST, address, bank info
│   │   │   ├── BuddyReturnController.php      # Return requests, inspection, refund decisions
│   │   │   ├── BuddyReviewController.php      # Seller replies & admin review moderation
│   │   │   └── BuddySettingsController.php    # Platform commissions & general configuration
│   │   ├── Customer/               # TechKart Customer Mobile App Controllers
│   │   │   ├── CustomerAddressController.php  # Shipping address book management
│   │   │   ├── CustomerAiController.php       # Multimodal visual search & Gemini AI chat
│   │   │   ├── CustomerAuthController.php     # Customer registration, OTP verification, JWT auth
│   │   │   ├── CustomerCartController.php     # Cart management with server-side pricing
│   │   │   ├── CustomerCatalogController.php  # Public product browse, categories, filters
│   │   │   ├── CustomerOrderController.php    # Checkout preview, atomic order placement, history
│   │   │   ├── CustomerPaymentController.php  # Payment intent initiation & verification
│   │   │   ├── CustomerReturnController.php   # Return eligibility check, submission, tracking
│   │   │   ├── CustomerReviewController.php   # Product review submissions, ratings, helpful votes
│   │   │   └── CustomerWishlistController.php # Customer saved products / wishlist
│   │   └── HealthController.php    # API health check and database ping
│   ├── Core/                       # Framework Kernel
│   │   ├── Database.php            # Singleton PDO database connector with persistent connection
│   │   ├── Env.php                 # Pure-PHP .env environment variable parser
│   │   ├── Jwt.php                 # Hardened HMAC-SHA256 JWT encoding, decoding, and rotation
│   │   ├── Request.php             # HTTP request wrapper (body, headers, query params, files)
│   │   ├── Response.php            # Standardized JSON response formatting
│   │   └── Router.php              # Regular-expression RESTful router with URL parameter binding
│   ├── Middlewares/                # Request Filter Pipeline
│   │   ├── AuthMiddleware.php      # JWT Bearer token validator & RBAC role enforcement
│   │   └── CorsMiddleware.php      # Cross-Origin Resource Sharing headers & OPTIONS preflight
│   └── Services/                   # External Services & Specialized Business Logic
│       ├── AiChatbotService.php       # Context-aware multilingual e-commerce chatbot
│       ├── GeminiApiService.php       # Google Generative AI cURL client with SSL validation
│       ├── MailService.php            # Native SMTP socket mailer for OTP delivery
│       └── VisualSearchService.php    # Reverse image search and product matching logic
├── bootstrap/
│   └── app.php                     # App initialization, PSR-4 autoloader & secured exception handler
├── database/
│   ├── migrations/                 # 18 Modular SQL migrations tracking the database schema
│   │   ├── 001_initial_schema.sql
│   │   ├── 002_cart_schema.sql
│   │   ├── 003_checkout_orders_payments.sql
│   │   ├── ...
│   │   └── 018_refresh_tokens.sql
│   └── seeds / migration runners   # Database seeding scripts for demo and test environments
├── public/
│   ├── assets/                     # Public uploaded assets & category thumbnails
│   ├── .htaccess                   # Apache URL rewriting to index.php
│   └── index.php                   # Single point of entry for all incoming HTTP requests
├── routes/
│   └── api_v1.php                  # Unified API route declarations
├── tests/                          # Automated backend integration and regression test suites
├── .env.example                    # Template for environment configuration
├── .gitignore                      # Git ignore file preventing leakage of secrets and temp logs
└── README.md                       # Comprehensive project documentation
```

---

## 🔒 Security & Hardening

1. **Mandatory Non-Empty JWT Secret**: The JWT engine rejects startup or token issuance if `JWT_SECRET` is unset or blank, preventing insecure fallback signature vulnerabilities.
2. **Hardened Global Exception Handler**:
   - In production (`APP_DEBUG=false`), internal error messages and stack traces are suppressed, returning generic `500 Internal Server Error` responses.
   - All unexpected errors and traces are logged server-side via `error_log()` for diagnostic observability without leaking infrastructure details.
3. **Restricted Payment Simulation**:
   - Client-simulated payment confirmation is gated strictly to local/development environments (`APP_ENV=development|local|testing`). Production environments block client-side success overrides with `403 Forbidden`.
4. **Transport Layer Security**:
   - `GeminiApiService` enforces strict SSL peer and host verification (`CURLOPT_SSL_VERIFYPEER = true`, `CURLOPT_SSL_VERIFYHOST = 2`), blocking man-in-the-middle attacks.
   - Raw provider response bodies are stripped from server logs to prevent leaking authorization headers or prompt contents.
5. **Role Separation & Session Expiration**:
   - Passwords changed on any account instantly invalidate older JWT tokens by comparing the token's `iat` (Issued At) claim against the user's `password_changed_at` timestamp.
   - Refresh tokens feature replay-attack detection: if a previously revoked refresh token is presented, all active sessions for that user are immediately invalidated.

---

## 🚀 Installation & Local Setup

### 1. Prerequisites
- **PHP 8.2 or higher** with extensions: `pdo`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `json`.
- **MySQL 8.0+** or **MariaDB 10.5+**.
- Apache (with `mod_rewrite` enabled) or Nginx.

### 2. Database Initialization
Create the database and apply the database migrations in sequential order:
```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS techkart_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Apply migration files sequentially
mysql -u root -p techkart_db < database/migrations/001_initial_schema.sql
mysql -u root -p techkart_db < database/migrations/002_cart_schema.sql
mysql -u root -p techkart_db < database/migrations/003_checkout_orders_payments.sql
mysql -u root -p techkart_db < database/migrations/004_wishlist_schema.sql
mysql -u root -p techkart_db < database/migrations/005_reviews_ratings_schema.sql
mysql -u root -p techkart_db < database/migrations/006_shipment_tracking_schema.sql
mysql -u root -p techkart_db < database/migrations/007_ai_search_chat_schema.sql
mysql -u root -p techkart_db < database/migrations/008_buddy_admin_seller_schema.sql
mysql -u root -p techkart_db < database/migrations/009_product_approval_status.sql
mysql -u root -p techkart_db < database/migrations/010_low_stock_alerts_schema.sql
mysql -u root -p techkart_db < database/migrations/011_review_replies_and_moderation.sql
mysql -u root -p techkart_db < database/migrations/012_return_requests_alignment.sql
mysql -u root -p techkart_db < database/migrations/015_add_gender_to_users.sql
mysql -u root -p techkart_db < database/migrations/016_password_reset_and_security.sql
mysql -u root -p techkart_db < database/migrations/017_registration_otps.sql
mysql -u root -p techkart_db < database/migrations/018_refresh_tokens.sql
```

*(Optional: Run demo seeds)*
```bash
php database/seed_45_categories.php
php database/seed_buddy_demo_records.php
```

### 3. Running the Development Server
Launch the PHP built-in web server targeting the `public` directory:
```bash
php -S 127.0.0.1:8000 -t public
```
Verify connectivity by opening: `http://127.0.0.1:8000/api/v1/health`

---

## 🗄 Database Schema & Migrations

The database consists of 18 migration scripts establishing an enterprise e-commerce model:

| Migration | Purpose | Core Tables / Changes |
|---|---|---|
| `001_initial_schema.sql` | Core schema | `users`, `categories`, `products`, `product_images`, `orders`, `order_items` |
| `002_cart_schema.sql` | Customer shopping cart | `cart_items` with unique customer-product constraints |
| `003_checkout_orders_payments.sql` | Addresses & payments | `addresses`, `payments`, `idempotency_keys` |
| `004_wishlist_schema.sql` | Customer wishlist | `wishlist_items` |
| `005_reviews_ratings_schema.sql` | Feedback & social proof | `product_reviews`, `review_helpful_votes` |
| `006_shipment_tracking_schema.sql` | Tracking milestones | `shipment_events`, courier tracking fields |
| `007_ai_search_chat_schema.sql` | Gemini AI history | `ai_chat_sessions`, `ai_chat_messages` |
| `008_buddy_admin_seller_schema.sql` | Multi-vendor commerce | `seller_profiles`, `settlements`, `admin_audit_logs` |
| `009_product_approval_status.sql` | Admin product approval | Approval status enums (`draft`, `pending_approval`, `approved`, `rejected`) |
| `010_low_stock_alerts_schema.sql` | Inventory alerts | `low_stock_threshold` on products |
| `011_review_replies_and_moderation.sql`| Review management | `review_replies`, moderation statuses |
| `012_return_requests_alignment.sql` | Reverse logistics | `return_requests`, `return_items`, pickup details |
| `013_device_tokens_schema.sql` | Historical device registry | `device_tokens` (retained for backward schema compatibility) |
| `014_notification_logs_schema.sql` | Historical notification logs | `notification_logs` (retained for backward schema compatibility) |
| `015_add_gender_to_users.sql` | User demographic data | `gender` column on `users` |
| `016_password_reset_and_security.sql` | Password reset OTPs | `password_reset_otps`, `password_changed_at`, `token_blacklist` |
| `017_registration_otps.sql` | Registration email OTPs | `registration_otps` table |
| `018_refresh_tokens.sql` | Long-lived refresh tokens | `refresh_tokens` table with rotation and replay detection |

---

## 📡 Comprehensive API Specification

All API endpoints return standard JSON responses:

**Success Response Format:**
```json
{
  "success": true,
  "status": 200,
  "message": "Descriptive success message",
  "data": { ... }
}
```

**Error Response Format:**
```json
{
  "success": false,
  "status": 400,
  "message": "Descriptive error message",
  "errors": { "field": "Validation details" }
}
```

---

### 1. System Health

#### `GET /api/v1/health`
Checks server readiness and database ping.
- **Auth**: None
- **Response `200 OK`**:
```json
{
  "success": true,
  "status": 200,
  "message": "TechKart API is operational",
  "data": {
    "app": "TechKart Unified Backend",
    "version": "2.0.0",
    "timestamp": "2026-11-04 04:00:00",
    "database": "connected"
  }
}
```

---

### 2. Customer Authentication & Account Security

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/customer/auth/register` | None | Register new customer; sends verification OTP |
| `POST` | `/api/v1/customer/auth/verify-register-otp` | None | Verify registration OTP and issue initial JWT session |
| `POST` | `/api/v1/customer/auth/resend-register-otp` | None | Resend registration OTP (rate-limited) |
| `POST` | `/api/v1/customer/auth/login` | None | Sign in with email and password |
| `POST` | `/api/v1/customer/auth/refresh-token` | None | Rotate refresh token and obtain fresh access token |
| `POST` | `/api/v1/customer/auth/forgot-password` | None | Send password recovery OTP via email |
| `POST` | `/api/v1/customer/auth/verify-reset-otp` | None | Validate recovery OTP |
| `POST` | `/api/v1/customer/auth/reset-password` | None | Set new password with valid OTP |
| `GET` | `/api/v1/customer/auth/profile` | Customer | Fetch current customer profile |
| `PUT/PATCH` | `/api/v1/customer/auth/profile` | Customer | Update name, phone, gender, avatar |
| `POST` | `/api/v1/customer/auth/logout` | Customer | Blacklist current JWT and revoke refresh tokens |

---

### 3. Buddy Authentication (Sellers & Administrators)

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/buddy/auth/register-seller` | None | Register seller account and create store profile |
| `POST` | `/api/v1/buddy/auth/login` | None | Authenticate seller or administrator |
| `GET` | `/api/v1/buddy/auth/profile` | Seller/Admin | Retrieve merchant profile and store settings |
| `PUT/PATCH` | `/api/v1/buddy/auth/profile` | Seller/Admin | Update seller personal information |
| `POST` | `/api/v1/buddy/auth/logout` | Seller/Admin | Log out and invalidate active session tokens |

---

### 4. Customer Catalog & Category Discovery

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/customer/categories` | None | List top-level product categories |
| `GET` | `/api/v1/customer/categories/{id}/subcategories` | None | List subcategories for a given parent category |
| `GET` | `/api/v1/customer/products` | None | Search, filter (price, category, stock), and paginate published products |
| `GET` | `/api/v1/customer/products/{id}` | None | View complete product details, seller info, and gallery |

---

### 4. Shopping Cart & Inventory Validation

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/customer/cart` | Customer | View cart with server-calculated subtotals and discounts |
| `POST` | `/api/v1/customer/cart` | Customer | Add product to cart (validates inventory limits) |
| `PUT/PATCH` | `/api/v1/customer/cart/{id}` | Customer | Update cart item quantity (0 removes item) |
| `DELETE` | `/api/v1/customer/cart/{id}` | Customer | Remove specific item from cart |
| `DELETE` | `/api/v1/customer/cart` | Customer | Empty the customer's cart |

---

### 5. Customer Wishlist

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/customer/wishlist` | Customer | View saved items in wishlist |
| `POST` | `/api/v1/customer/wishlist` | Customer | Save a product to wishlist |
| `DELETE` | `/api/v1/customer/wishlist/{id}` | Customer | Remove product from wishlist |
| `DELETE` | `/api/v1/customer/wishlist` | Customer | Clear all wishlist items |

---

### 6. Delivery Addresses

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/customer/addresses` | Customer | List saved delivery addresses |
| `POST` | `/api/v1/customer/addresses` | Customer | Add new address (supports `is_default` flag) |
| `PUT/PATCH` | `/api/v1/customer/addresses/{id}` | Customer | Update an existing address |
| `DELETE` | `/api/v1/customer/addresses/{id}` | Customer | Delete address |

---

### 7. Checkout & Order Placement

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/customer/checkout/preview` | Customer | Calculate order breakdown, taxes, and shipping before buying |
| `POST` | `/api/v1/customer/orders` | Customer | Place order atomically from cart items (supports `Idempotency-Key`) |
| `GET` | `/api/v1/customer/orders` | Customer | List past orders with status filter |
| `GET` | `/api/v1/customer/orders/{id}` | Customer | Get full invoice, items snapshot, and logistics status |
| `POST` | `/api/v1/customer/orders/{id}/cancel` | Customer | Cancel unshipped order & automatically restore inventory |

---

### 8. Payments & Payment Verification

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/customer/payments/initiate` | Customer | Generate transaction reference and client payment intent |
| `POST` | `/api/v1/customer/payments/verify` | Customer | Confirm transaction (simulated verification strictly dev-only) |

---

### 9. Live Order Tracking & Logistics

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/customer/orders/{id}/tracking` | Customer | Retrieve real-time checkpoint events, courier AWB, and ETA |

---

### 10. Customer Product Reviews & Ratings

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/customer/products/{id}/reviews` | None | List approved reviews and rating breakdown for product |
| `POST` | `/api/v1/customer/products/{id}/reviews` | Customer | Submit verified review with rating (1-5 stars) and title |
| `PUT/PATCH` | `/api/v1/customer/reviews/{id}` | Customer | Edit your own submitted review |
| `DELETE` | `/api/v1/customer/reviews/{id}` | Customer | Delete your review |
| `POST` | `/api/v1/customer/reviews/{id}/helpful` | Customer | Upvote review helpfulness |

---

### 11. Customer Returns & Refunds

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/customer/returns/eligibility/{order_id}` | Customer | Check return window and item return eligibility |
| `POST` | `/api/v1/customer/returns` | Customer | Submit return request with return reason and bank details |
| `GET` | `/api/v1/customer/returns` | Customer | List customer return requests |
| `GET` | `/api/v1/customer/returns/{id}` | Customer | View return status and reverse logistics timeline |

---

### 12. AI Multimodal Visual Search & Chat Assistant

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/customer/ai/visual-search` | None | Upload an image to detect product category, title, and match items |
| `POST` | `/api/v1/customer/ai/chat/message` | None | Send a query to the Gemini e-commerce shopping assistant |
| `GET` | `/api/v1/customer/ai/chat/sessions` | Customer | List customer AI chat session history |
| `GET` | `/api/v1/customer/ai/chat/sessions/{id}/messages` | Customer | Retrieve message transcript for a chat session |

---

### 13. Buddy Merchant Store & Profile

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/buddy/seller/profile` | Seller | Retrieve store name, GST, bank account, and address |
| `PUT/PATCH` | `/api/v1/buddy/seller/profile` | Seller | Update business and payout details |

---

### 14. Buddy Merchant Catalog & Product Approval Lifecycle

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/buddy/products` | Seller/Admin | List products owned by seller (or all for admin) |
| `POST` | `/api/v1/buddy/products` | Seller/Admin | Create product (enforces price <= MRP and stock limits) |
| `GET` | `/api/v1/buddy/products/{id}` | Seller/Admin | View product specifications and image variants |
| `PUT/PATCH` | `/api/v1/buddy/products/{id}` | Seller/Admin | Update product fields and pricing |
| `DELETE` | `/api/v1/buddy/products/{id}` | Seller/Admin | Delete product and associated image gallery |
| `POST` | `/api/v1/buddy/seller/products/{id}/submit-approval`| Seller | Submit draft product for admin moderation |
| `PUT/PATCH` | `/api/v1/buddy/admin/products/{id}/approval-status` | Admin | Approve or reject submitted seller product |

---

### 15. Buddy Inventory Control & Low Stock Alerts

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/buddy/inventory` | Seller/Admin | View inventory stock levels and replenishment statuses |
| `PATCH` | `/api/v1/buddy/inventory/stock` | Seller/Admin | Perform atomic batch inventory adjustments |
| `GET` | `/api/v1/buddy/seller/inventory/low-stock-alerts` | Seller | View products falling below configured safety threshold |
| `PUT/PATCH` | `/api/v1/buddy/seller/products/{id}/low-stock-threshold` | Seller | Configure low stock threshold for a product |

---

### 16. Buddy Order Fulfillment & Shipping

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/buddy/orders` | Seller/Admin | View orders containing items from merchant's store |
| `GET` | `/api/v1/buddy/orders/{id}` | Seller/Admin | View itemized order details, buyer address, and financials |
| `POST` | `/api/v1/buddy/orders/{id}/accept` | Seller/Admin | Accept order and mark items for packaging |
| `POST` | `/api/v1/buddy/orders/{id}/reject` | Seller/Admin | Reject order item with descriptive explanation |
| `PATCH/PUT` | `/api/v1/buddy/orders/{id}/status` | Seller/Admin | Update item status (`processing`, `shipped`, `delivered`) |
| `POST` | `/api/v1/buddy/orders/{id}/shipping` | Seller/Admin | Dispatch shipment with courier name and AWB tracking number |

---

### 17. Buddy Returns Decision & Reverse Logistics

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/buddy/seller/returns` | Seller | View return requests submitted for seller's items |
| `GET` | `/api/v1/buddy/seller/returns/{id}` | Seller | Inspect return item, customer photos, and reasons |
| `POST` | `/api/v1/buddy/seller/returns/{id}/decision` | Seller | Approve return, schedule pickup, or reject request |
| `POST` | `/api/v1/buddy/admin/returns/{id}/decision` | Admin | Administrative override and refund release |

---

### 18. Buddy Merchant & Admin Review Moderation

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/buddy/seller/reviews` | Seller | View customer reviews on store products |
| `POST/PUT` | `/api/v1/buddy/seller/reviews/{id}/reply` | Seller | Post official merchant response to a customer review |
| `GET` | `/api/v1/buddy/admin/reviews` | Admin | Review moderation queue across the entire platform |
| `PUT/PATCH` | `/api/v1/buddy/admin/reviews/{id}/moderate` | Admin | Approve, flag, or hide reviews |

---

### 19. Buddy Analytics, Revenue Reports & Financial Settlements

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/buddy/dashboard/stats` | Seller/Admin | Real-time sales, order counts, pending actions |
| `GET` | `/api/v1/buddy/reports/sales-revenue` | Seller/Admin | Sales trends, commission deductions, net revenue |
| `GET` | `/api/v1/buddy/admin/settlements` | Admin | Overview of merchant payout settlements |
| `POST` | `/api/v1/buddy/seller/settlements/request-payout` | Seller | Request disbursement of matured balance |
| `POST/PUT` | `/api/v1/buddy/admin/settlements/{id}/release` | Admin | Authorize bank transfer payout to seller |

---

### 20. Buddy Administrative Controls

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/v1/buddy/admin/customers` | Admin | Platform customer directory with registration dates |
| `GET` | `/api/v1/buddy/admin/customers/{id}` | Admin | Customer profile, address book, and order volume |
| `PUT/PATCH` | `/api/v1/buddy/admin/customers/{id}/status` | Admin | Deactivate or restore customer account |
| `GET` | `/api/v1/buddy/admin/categories` | Admin | Manage catalog category hierarchy |
| `POST` | `/api/v1/buddy/admin/categories` | Admin | Create new product category or subcategory |
| `PUT/PATCH` | `/api/v1/buddy/admin/categories/{id}` | Admin | Update category name, slug, image, commission rate |
| `DELETE` | `/api/v1/buddy/admin/categories/{id}` | Admin | Delete category (safeguards children) |
| `GET` | `/api/v1/buddy/admin/audit-logs` | Admin | Immutable platform audit trail for sensitive actions |
| `GET` | `/api/v1/buddy/admin/settings` | Admin | View platform settings and category commission rules |
| `PUT/PATCH` | `/api/v1/buddy/admin/settings` | Admin | Update global platform fees, thresholds, and limits |

---

## 🧪 Automated Testing & Verification

The backend includes a comprehensive suite of automated integration, security, and flow tests written in native PHP.

### Running Test Suites

Execute test suites directly from the project root using PHP CLI:

```bash
# 1. Product Catalog & Search Filtering
php test_product_catalog.php

# 2. Shopping Cart, Pricing & Stock Enforcement
php test_cart_api.php

# 3. Checkout, Atomic Orders & Payments
php test_checkout_orders_payments.php

# 4. End-to-End Complete Checkout Lifecycle
php test_e2e_checkout_flow.php

# 5. Customer Email Authentication & OTP Lifecycle
php test_customer_email_auth.php

# 6. Customer Wishlist Operations
php test_wishlist_api.php

# 7. Customer Reviews, Ratings & Votes
php test_reviews_api.php

# 8. Customer Returns & Refunds Flow
php test_returns_refunds_api.php

# 9. Buddy Admin Audit Logging Trail
php test_admin_audit_logs.php

# 10. Production Readiness & Security Verification
php test_customer_production_readiness.php
php test_buddy_production_readiness.php

# 11. Consolidated Master Audit
php test_consolidated_master_audit.php
```

### Verified Test Status

| Test Suite | Assertions Passed | Result |
|---|---|---|
| `test_product_catalog.php` | 21 / 21 | **PASS** |
| `test_cart_api.php` | 13 / 13 | **PASS** |
| `test_checkout_orders_payments.php` | 16 / 16 | **PASS** |
| `test_e2e_checkout_flow.php` | 14 / 14 | **PASS** |
| `test_returns_refunds_api.php` | 12 / 12 | **PASS** |
| `test_reviews_api.php` | 10 / 10 | **PASS** |
| `test_wishlist_api.php` | 8 / 8 | **PASS** |
| `test_registration_otp_e2e.php` | 7 / 7 | **PASS** |

---

## 📄 License & Authors

- **Platform**: TechKart Unified E-Commerce Platform
- **License**: Proprietary / Private Commercial Software
- **Designed for**: TechKart Customer Mobile App & TechKart Buddy Merchant/Admin Portal
