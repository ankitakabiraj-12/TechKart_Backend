<?php

declare(strict_types=1);

use App\Core\Router;
use App\Controllers\HealthController;
use App\Controllers\Customer\CustomerAuthController;
use App\Controllers\Buddy\BuddyAuthController;
use App\Middlewares\AuthMiddleware;

/** @var Router $router */

// -------------------------------------------------------------
// Root & Health Verification Routes
// -------------------------------------------------------------
$router->get('/api/v1/health', [HealthController::class, 'check']);

// -------------------------------------------------------------
// Customer API Routes (TechKart Customer App)
// -------------------------------------------------------------
$customerAuthGuard = new AuthMiddleware(['customer']);
$router->post('/api/v1/customer/auth/register', [CustomerAuthController::class, 'register']);
$router->post('/api/v1/customer/auth/resend-register-otp', [CustomerAuthController::class, 'resendRegisterOtp']);
$router->post('/api/v1/customer/auth/verify-register-otp', [CustomerAuthController::class, 'verifyRegisterOtp']);
$router->post('/api/v1/customer/auth/login', [CustomerAuthController::class, 'login']);
$router->post('/api/v1/customer/auth/refresh-token', [CustomerAuthController::class, 'refreshToken']);
$router->post('/api/v1/customer/auth/forgot-password', [CustomerAuthController::class, 'forgotPassword']);
$router->post('/api/v1/customer/auth/verify-reset-otp', [CustomerAuthController::class, 'verifyResetOtp']);
$router->post('/api/v1/customer/auth/reset-password', [CustomerAuthController::class, 'resetPassword']);
$router->get('/api/v1/customer/auth/profile', [CustomerAuthController::class, 'profile'], [$customerAuthGuard]);
$router->put('/api/v1/customer/auth/profile', [CustomerAuthController::class, 'updateProfile'], [$customerAuthGuard]);
$router->patch('/api/v1/customer/auth/profile', [CustomerAuthController::class, 'updateProfile'], [$customerAuthGuard]);
$router->post('/api/v1/customer/auth/logout', [CustomerAuthController::class, 'logout'], [$customerAuthGuard]);

// Public Customer Catalog & Category Routes
$router->get('/api/v1/customer/categories', [\App\Controllers\Customer\CustomerCatalogController::class, 'categories']);
$router->get('/api/v1/customer/categories/{id}/subcategories', [\App\Controllers\Customer\CustomerCatalogController::class, 'subcategories']);
$router->get('/api/v1/customer/products', [\App\Controllers\Customer\CustomerCatalogController::class, 'index']);
$router->get('/api/v1/customer/products/{id}', [\App\Controllers\Customer\CustomerCatalogController::class, 'show']);

// Authenticated Customer Cart Routes
$router->get('/api/v1/customer/cart', [\App\Controllers\Customer\CustomerCartController::class, 'index'], [$customerAuthGuard]);
$router->post('/api/v1/customer/cart', [\App\Controllers\Customer\CustomerCartController::class, 'store'], [$customerAuthGuard]);
$router->put('/api/v1/customer/cart/{id}', [\App\Controllers\Customer\CustomerCartController::class, 'update'], [$customerAuthGuard]);
$router->patch('/api/v1/customer/cart/{id}', [\App\Controllers\Customer\CustomerCartController::class, 'update'], [$customerAuthGuard]);
$router->delete('/api/v1/customer/cart/{id}', [\App\Controllers\Customer\CustomerCartController::class, 'destroy'], [$customerAuthGuard]);
$router->delete('/api/v1/customer/cart', [\App\Controllers\Customer\CustomerCartController::class, 'clear'], [$customerAuthGuard]);

// Authenticated Customer Wishlist Routes (SCR-24)
$router->get('/api/v1/customer/wishlist', [\App\Controllers\Customer\CustomerWishlistController::class, 'index'], [$customerAuthGuard]);
$router->post('/api/v1/customer/wishlist', [\App\Controllers\Customer\CustomerWishlistController::class, 'store'], [$customerAuthGuard]);
$router->delete('/api/v1/customer/wishlist/{id}', [\App\Controllers\Customer\CustomerWishlistController::class, 'destroy'], [$customerAuthGuard]);
$router->delete('/api/v1/customer/wishlist', [\App\Controllers\Customer\CustomerWishlistController::class, 'clear'], [$customerAuthGuard]);

// Authenticated Customer Address Routes (SCR-26, SCR-27, SCR-39)
$router->get('/api/v1/customer/addresses', [\App\Controllers\Customer\CustomerAddressController::class, 'index'], [$customerAuthGuard]);
$router->post('/api/v1/customer/addresses', [\App\Controllers\Customer\CustomerAddressController::class, 'store'], [$customerAuthGuard]);
$router->put('/api/v1/customer/addresses/{id}', [\App\Controllers\Customer\CustomerAddressController::class, 'update'], [$customerAuthGuard]);
$router->patch('/api/v1/customer/addresses/{id}', [\App\Controllers\Customer\CustomerAddressController::class, 'update'], [$customerAuthGuard]);
$router->delete('/api/v1/customer/addresses/{id}', [\App\Controllers\Customer\CustomerAddressController::class, 'destroy'], [$customerAuthGuard]);

// Authenticated Customer Checkout & Orders Routes (SCR-28, SCR-31, SCR-32, SCR-33)
$router->get('/api/v1/customer/checkout/preview', [\App\Controllers\Customer\CustomerOrderController::class, 'preview'], [$customerAuthGuard]);
$router->post('/api/v1/customer/orders', [\App\Controllers\Customer\CustomerOrderController::class, 'store'], [$customerAuthGuard]);
$router->get('/api/v1/customer/orders', [\App\Controllers\Customer\CustomerOrderController::class, 'index'], [$customerAuthGuard]);
$router->get('/api/v1/customer/orders/{id}', [\App\Controllers\Customer\CustomerOrderController::class, 'show'], [$customerAuthGuard]);
$router->get('/api/v1/customer/orders/{id}/tracking', [\App\Controllers\Customer\CustomerOrderController::class, 'track'], [$customerAuthGuard]);
$router->post('/api/v1/customer/orders/{id}/cancel', [\App\Controllers\Customer\CustomerOrderController::class, 'cancel'], [$customerAuthGuard]);

// Authenticated Customer Payments Routes (SCR-29, SCR-30)
$router->post('/api/v1/customer/payments/initiate', [\App\Controllers\Customer\CustomerPaymentController::class, 'initiate'], [$customerAuthGuard]);
$router->post('/api/v1/customer/payments/verify', [\App\Controllers\Customer\CustomerPaymentController::class, 'verify'], [$customerAuthGuard]);

// Public & Authenticated Customer Reviews & Ratings Routes (SCR-08, SCR-09)
$router->get('/api/v1/customer/products/{id}/reviews', [\App\Controllers\Customer\CustomerReviewController::class, 'index']);
$router->post('/api/v1/customer/products/{id}/reviews', [\App\Controllers\Customer\CustomerReviewController::class, 'store'], [$customerAuthGuard]);
$router->put('/api/v1/customer/reviews/{id}', [\App\Controllers\Customer\CustomerReviewController::class, 'update'], [$customerAuthGuard]);
$router->patch('/api/v1/customer/reviews/{id}', [\App\Controllers\Customer\CustomerReviewController::class, 'update'], [$customerAuthGuard]);
$router->delete('/api/v1/customer/reviews/{id}', [\App\Controllers\Customer\CustomerReviewController::class, 'destroy'], [$customerAuthGuard]);
$router->post('/api/v1/customer/reviews/{id}/helpful', [\App\Controllers\Customer\CustomerReviewController::class, 'voteHelpful'], [$customerAuthGuard]);

// -------------------------------------------------------------
// Customer AI Visual Search & Chatbot Routes (SCR-19, SCR-20, SCR-21, SCR-22, SCR-23)
// -------------------------------------------------------------
$router->post('/api/v1/customer/ai/visual-search', [\App\Controllers\Customer\CustomerAiController::class, 'visualSearch']);
$router->post('/api/v1/customer/ai/chat/message', [\App\Controllers\Customer\CustomerAiController::class, 'sendMessage']);
$router->get('/api/v1/customer/ai/chat/sessions', [\App\Controllers\Customer\CustomerAiController::class, 'getSessions']);
$router->get('/api/v1/customer/ai/chat/sessions/{id}/messages', [\App\Controllers\Customer\CustomerAiController::class, 'getSessionMessages']);

// -------------------------------------------------------------
// Buddy API Routes (TechKart Buddy Seller & Admin App)
// -------------------------------------------------------------
$router->post('/api/v1/buddy/auth/register-seller', [BuddyAuthController::class, 'registerSeller']);
$router->post('/api/v1/buddy/auth/login', [BuddyAuthController::class, 'login']);
$router->get(
    '/api/v1/buddy/auth/profile',
    [BuddyAuthController::class, 'profile'],
    [new AuthMiddleware(['seller', 'admin'])]
);
$router->get(
    '/api/v1/buddy/auth/validate-session',
    [BuddyAuthController::class, 'validateSession'],
    [new AuthMiddleware(['seller', 'admin'])]
);
$router->post(
    '/api/v1/buddy/auth/logout',
    [BuddyAuthController::class, 'logout'],
    [new AuthMiddleware(['seller', 'admin'])]
);

// Seller-Protected Catalog Management Routes
$sellerAuthGuard = new AuthMiddleware(['seller', 'admin']);
$router->get('/api/v1/buddy/categories', [\App\Controllers\Buddy\BuddyCategoryController::class, 'index'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/categories/{id}', [\App\Controllers\Buddy\BuddyCategoryController::class, 'show'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/categories/{id}/subcategories', [\App\Controllers\Buddy\BuddyCategoryController::class, 'subcategories'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/categories/{id}/specifications', [\App\Controllers\Buddy\BuddyCategoryController::class, 'specifications'], [$sellerAuthGuard]);

$router->get('/api/v1/buddy/products', [\App\Controllers\Buddy\BuddyProductController::class, 'index'], [$sellerAuthGuard]);
$router->post('/api/v1/buddy/products', [\App\Controllers\Buddy\BuddyProductController::class, 'store'], [$sellerAuthGuard]);
$router->post('/api/v1/buddy/products/upload-image', [\App\Controllers\Buddy\BuddyProductController::class, 'uploadImages'], [$sellerAuthGuard]);
$router->post('/api/v1/buddy/products/{id}/images', [\App\Controllers\Buddy\BuddyProductController::class, 'uploadImages'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/products/{id}', [\App\Controllers\Buddy\BuddyProductController::class, 'show'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/products/{id}', [\App\Controllers\Buddy\BuddyProductController::class, 'update'], [$sellerAuthGuard]);
$router->patch('/api/v1/buddy/products/{id}', [\App\Controllers\Buddy\BuddyProductController::class, 'update'], [$sellerAuthGuard]);
$router->delete('/api/v1/buddy/products/{id}', [\App\Controllers\Buddy\BuddyProductController::class, 'destroy'], [$sellerAuthGuard]);
$router->post('/api/v1/buddy/products/{id}/review', [\App\Controllers\Buddy\BuddyProductController::class, 'reviewProduct'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/products/{id}/review', [\App\Controllers\Buddy\BuddyProductController::class, 'reviewProduct'], [$sellerAuthGuard]);

// Seller-Protected Inventory & Stock Management Routes
$router->get('/api/v1/buddy/inventory', [\App\Controllers\Buddy\BuddyInventoryController::class, 'index'], [$sellerAuthGuard]);
$router->post('/api/v1/buddy/inventory/update-stock', [\App\Controllers\Buddy\BuddyInventoryController::class, 'updateStock'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/inventory/update-stock', [\App\Controllers\Buddy\BuddyInventoryController::class, 'updateStock'], [$sellerAuthGuard]);
$router->patch('/api/v1/buddy/inventory/update-stock', [\App\Controllers\Buddy\BuddyInventoryController::class, 'updateStock'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/inventory/movements', [\App\Controllers\Buddy\BuddyInventoryController::class, 'movements'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/inventory/alerts', [\App\Controllers\Buddy\BuddyInventoryController::class, 'alerts'], [$sellerAuthGuard]);
$router->post('/api/v1/buddy/inventory/alerts/mark-all-read', [\App\Controllers\Buddy\BuddyInventoryController::class, 'markAllAlertsRead'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/inventory/alerts/mark-all-read', [\App\Controllers\Buddy\BuddyInventoryController::class, 'markAllAlertsRead'], [$sellerAuthGuard]);
$router->post('/api/v1/buddy/inventory/alerts/{id}/read', [\App\Controllers\Buddy\BuddyInventoryController::class, 'markAlertRead'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/inventory/alerts/{id}/read', [\App\Controllers\Buddy\BuddyInventoryController::class, 'markAlertRead'], [$sellerAuthGuard]);
$router->post('/api/v1/buddy/inventory/threshold', [\App\Controllers\Buddy\BuddyInventoryController::class, 'updateThreshold'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/inventory/threshold', [\App\Controllers\Buddy\BuddyInventoryController::class, 'updateThreshold'], [$sellerAuthGuard]);

// Authenticated Customer Returns & Refunds Routes (SCR-35, SCR-36)
$router->get('/api/v1/customer/orders/{id}/return-eligibility', [\App\Controllers\Customer\CustomerReturnController::class, 'checkEligibility'], [$customerAuthGuard]);
$router->post('/api/v1/customer/returns', [\App\Controllers\Customer\CustomerReturnController::class, 'store'], [$customerAuthGuard]);
$router->get('/api/v1/customer/returns/{id}', [\App\Controllers\Customer\CustomerReturnController::class, 'show'], [$customerAuthGuard]);

// Seller & Admin Order Fulfillment Routes
$router->get('/api/v1/buddy/orders', [\App\Controllers\Buddy\BuddyOrderController::class, 'index'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/orders/{id}', [\App\Controllers\Buddy\BuddyOrderController::class, 'show'], [$sellerAuthGuard]);
$router->post('/api/v1/buddy/orders/{id}/accept', [\App\Controllers\Buddy\BuddyOrderController::class, 'accept'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/orders/{id}/accept', [\App\Controllers\Buddy\BuddyOrderController::class, 'accept'], [$sellerAuthGuard]);
$router->post('/api/v1/buddy/orders/{id}/reject', [\App\Controllers\Buddy\BuddyOrderController::class, 'reject'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/orders/{id}/reject', [\App\Controllers\Buddy\BuddyOrderController::class, 'reject'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/orders/{id}/status', [\App\Controllers\Buddy\BuddyOrderController::class, 'updateStatus'], [$sellerAuthGuard]);
$router->patch('/api/v1/buddy/orders/{id}/status', [\App\Controllers\Buddy\BuddyOrderController::class, 'updateStatus'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/orders/{id}/tracking', [\App\Controllers\Buddy\BuddyOrderController::class, 'track'], [$sellerAuthGuard]);

// Seller & Admin Return & Refund Decision Routes
$router->get('/api/v1/buddy/returns', [\App\Controllers\Buddy\BuddyReturnController::class, 'index'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/returns/{id}', [\App\Controllers\Buddy\BuddyReturnController::class, 'show'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/returns/{id}/decision', [\App\Controllers\Buddy\BuddyReturnController::class, 'decision'], [$sellerAuthGuard]);
$router->patch('/api/v1/buddy/returns/{id}/decision', [\App\Controllers\Buddy\BuddyReturnController::class, 'decision'], [$sellerAuthGuard]);

// Seller Profile & Store Management Routes
$router->get('/api/v1/buddy/seller/profile', [\App\Controllers\Buddy\BuddyProfileController::class, 'getProfile'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/seller/profile', [\App\Controllers\Buddy\BuddyProfileController::class, 'updateProfile'], [$sellerAuthGuard]);
$router->patch('/api/v1/buddy/seller/profile', [\App\Controllers\Buddy\BuddyProfileController::class, 'updateProfile'], [$sellerAuthGuard]);

// Admin Directory & Seller Verification Management
$adminAuthGuard = new AuthMiddleware(['admin']);
$router->get('/api/v1/buddy/admin/sellers', [\App\Controllers\Buddy\BuddyProfileController::class, 'adminSellersDirectory'], [$adminAuthGuard]);
$router->put('/api/v1/buddy/admin/sellers/{id}/verification', [\App\Controllers\Buddy\BuddyProfileController::class, 'updateVerificationStatus'], [$adminAuthGuard]);
$router->patch('/api/v1/buddy/admin/sellers/{id}/verification', [\App\Controllers\Buddy\BuddyProfileController::class, 'updateVerificationStatus'], [$adminAuthGuard]);

// Seller & Admin Dashboard & Management Routes
$router->get('/api/v1/buddy/seller/dashboard', [\App\Controllers\Buddy\BuddyDashboardController::class, 'sellerDashboard'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/seller/settlements', [\App\Controllers\Buddy\BuddyDashboardController::class, 'sellerSettlements'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/admin/dashboard', [\App\Controllers\Buddy\BuddyDashboardController::class, 'adminDashboard'], [$adminAuthGuard]);
$router->get('/api/v1/buddy/admin/seller-applications', [\App\Controllers\Buddy\BuddyDashboardController::class, 'sellerApplications'], [$adminAuthGuard]);
$router->put('/api/v1/buddy/admin/seller-applications/{id}/decision', [\App\Controllers\Buddy\BuddyDashboardController::class, 'applicationDecision'], [$adminAuthGuard]);
$router->patch('/api/v1/buddy/admin/seller-applications/{id}/decision', [\App\Controllers\Buddy\BuddyDashboardController::class, 'applicationDecision'], [$adminAuthGuard]);
$router->get('/api/v1/buddy/admin/audit-logs', [\App\Controllers\Buddy\BuddyDashboardController::class, 'auditLogs'], [$adminAuthGuard]);

// Sales & Revenue Reports Routes (Scoped for Seller, Global for Admin)
$router->get('/api/v1/buddy/reports/sales-revenue', [\App\Controllers\Buddy\BuddyDashboardController::class, 'salesAndRevenueReports'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/seller/reports/sales-revenue', [\App\Controllers\Buddy\BuddyDashboardController::class, 'salesAndRevenueReports'], [$sellerAuthGuard]);
$router->get('/api/v1/buddy/admin/reports/sales-revenue', [\App\Controllers\Buddy\BuddyDashboardController::class, 'salesAndRevenueReports'], [$adminAuthGuard]);

// Seller & Admin Settlement & Payout Routes
$router->get('/api/v1/buddy/admin/settlements', [\App\Controllers\Buddy\BuddyDashboardController::class, 'sellerSettlements'], [$adminAuthGuard]);
$router->post('/api/v1/buddy/seller/settlements/request-payout', [\App\Controllers\Buddy\BuddyDashboardController::class, 'requestPayout'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/admin/settlements/{id}/release', [\App\Controllers\Buddy\BuddyDashboardController::class, 'adminReleaseSettlement'], [$adminAuthGuard]);
$router->post('/api/v1/buddy/admin/settlements/{id}/release', [\App\Controllers\Buddy\BuddyDashboardController::class, 'adminReleaseSettlement'], [$adminAuthGuard]);

// Seller Reviews & Replies Routes
$router->get('/api/v1/buddy/seller/reviews', [\App\Controllers\Buddy\BuddyReviewController::class, 'sellerReviews'], [$sellerAuthGuard]);
$router->post('/api/v1/buddy/seller/reviews/{id}/reply', [\App\Controllers\Buddy\BuddyReviewController::class, 'replyToReview'], [$sellerAuthGuard]);
$router->put('/api/v1/buddy/seller/reviews/{id}/reply', [\App\Controllers\Buddy\BuddyReviewController::class, 'replyToReview'], [$sellerAuthGuard]);

// Admin Reviews & Content Moderation Routes
$router->get('/api/v1/buddy/admin/reviews', [\App\Controllers\Buddy\BuddyReviewController::class, 'adminReviews'], [$adminAuthGuard]);
$router->put('/api/v1/buddy/admin/reviews/{id}/moderate', [\App\Controllers\Buddy\BuddyReviewController::class, 'moderateReview'], [$adminAuthGuard]);
$router->patch('/api/v1/buddy/admin/reviews/{id}/moderate', [\App\Controllers\Buddy\BuddyReviewController::class, 'moderateReview'], [$adminAuthGuard]);

// Admin Customer Management Routes
$router->get('/api/v1/buddy/admin/customers', [\App\Controllers\Buddy\BuddyCustomerController::class, 'adminCustomersDirectory'], [$adminAuthGuard]);
$router->get('/api/v1/buddy/admin/customers/{id}', [\App\Controllers\Buddy\BuddyCustomerController::class, 'adminCustomerDetails'], [$adminAuthGuard]);
$router->put('/api/v1/buddy/admin/customers/{id}/status', [\App\Controllers\Buddy\BuddyCustomerController::class, 'updateCustomerStatus'], [$adminAuthGuard]);
$router->patch('/api/v1/buddy/admin/customers/{id}/status', [\App\Controllers\Buddy\BuddyCustomerController::class, 'updateCustomerStatus'], [$adminAuthGuard]);

// Admin Category Management Routes (CRUD)
$router->get('/api/v1/buddy/admin/categories', [\App\Controllers\Buddy\BuddyCategoryController::class, 'adminCategories'], [$adminAuthGuard]);
$router->post('/api/v1/buddy/admin/categories', [\App\Controllers\Buddy\BuddyCategoryController::class, 'createCategory'], [$adminAuthGuard]);
$router->put('/api/v1/buddy/admin/categories/{id}', [\App\Controllers\Buddy\BuddyCategoryController::class, 'updateCategory'], [$adminAuthGuard]);
$router->patch('/api/v1/buddy/admin/categories/{id}', [\App\Controllers\Buddy\BuddyCategoryController::class, 'updateCategory'], [$adminAuthGuard]);
$router->delete('/api/v1/buddy/admin/categories/{id}', [\App\Controllers\Buddy\BuddyCategoryController::class, 'deleteCategory'], [$adminAuthGuard]);

// Admin System Settings & Configurable Commission Rates Routes
$router->get('/api/v1/buddy/admin/settings', [\App\Controllers\Buddy\BuddySettingsController::class, 'getSettings'], [$adminAuthGuard]);
$router->put('/api/v1/buddy/admin/settings', [\App\Controllers\Buddy\BuddySettingsController::class, 'updateSettings'], [$adminAuthGuard]);
$router->patch('/api/v1/buddy/admin/settings', [\App\Controllers\Buddy\BuddySettingsController::class, 'updateSettings'], [$adminAuthGuard]);
$router->put('/api/v1/buddy/admin/settings/commissions/{category_id}', [\App\Controllers\Buddy\BuddySettingsController::class, 'updateCategoryCommission'], [$adminAuthGuard]);
$router->patch('/api/v1/buddy/admin/settings/commissions/{category_id}', [\App\Controllers\Buddy\BuddySettingsController::class, 'updateCategoryCommission'], [$adminAuthGuard]);
