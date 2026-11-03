<?php
/**
 * Comprehensive verification test for TechKart Buddy Product Approval Status:
 *
 * 1. Draft creation via POST /buddy/products (status: draft).
 * 2. Pending-review submission via POST /buddy/products (status: pending_review or seller submit).
 * 3. Preventing unapproved products (draft, pending_review, rejected) from appearing in customer catalog (GET /api/v1/customer/products).
 * 4. Preventing direct customer detail access to unapproved products (GET /api/v1/customer/products/{id} -> 404).
 * 5. Role-Based Access Control (RBAC):
 *    - Seller attempting to directly call admin review endpoint (POST /buddy/products/{id}/review -> 403 Forbidden).
 *    - Unauthorized user calling review endpoint (401 Unauthorized).
 * 6. Admin review actions:
 *    - Admin rejecting product with compliance feedback (status -> rejected, review_notes persisted).
 *    - Seller viewing rejection feedback on GET /buddy/products and GET /buddy/products/{id}.
 *    - Admin approving product (status -> approved).
 *    - Seller publishing approved product (status -> published).
 * 7. Verifying published product now appears in customer catalog.
 * 8. Re-edit flow: Seller updating rejected product resubmits to pending_review.
 */

$baseUrl = 'http://127.0.0.1:8000';

function postJson($url, $data, $token = null) {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

function putJson($url, $data, $token = null) {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

function getJson($url, $token = null) {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

function login($baseUrl, $email, $password) {
    $res = postJson($baseUrl . '/api/v1/buddy/auth/login', [
        'email' => $email,
        'password' => $password,
    ]);
    $token = $res['data']['token'] ?? $res['data']['data']['token'] ?? null;
    if ($res['status'] !== 200 || !$token) {
        throw new Exception("Login failed for $email: " . $res['raw']);
    }
    return $token;
}

echo "=== TECHKART BUDDY PRODUCT APPROVAL STATUS & RBAC VERIFICATION ===\n\n";

$passed = 0;
$total = 0;

function assertCondition($desc, $cond, $extra = '') {
    global $passed, $total;
    $total++;
    if ($cond) {
        $passed++;
        echo " [PASS] $desc\n";
    } else {
        echo " [FAIL] $desc " . ($extra ? "($extra)" : "") . "\n";
    }
}

// Acquire tokens
$sellerToken = login($baseUrl, 'vikram.store@techkart.com', 'password123');
$otherSellerToken = login($baseUrl, 'other.seller@techkart.com', 'password123');
$adminToken = login($baseUrl, 'admin.audit@techkart.com', 'password123');
echo "Tokens acquired: Seller 2, Seller 4, and Admin.\n";

// 1. Seller creates a DRAFT product
$draftPayload = [
    'title' => 'Test Gaming Mousepad RGB Edition',
    'category_id' => 6,
    'description' => 'Precision cloth surface with RGB perimeter lighting.',
    'price' => 1500.00,
    'mrp' => 1800.00,
    'stock_quantity' => 20,
    'status' => 'draft',
];
$draftRes = postJson($baseUrl . '/api/v1/buddy/products', $draftPayload, $sellerToken);
assertCondition("Seller creates draft product (201 Created)", $draftRes['status'] === 201);
$draftId = $draftRes['data']['data']['id'] ?? null;
assertCondition("Draft product ID is returned", $draftId > 0, "ID: $draftId");

// Verify draft status in Seller product details
$draftDetail = getJson($baseUrl . '/api/v1/buddy/products/' . $draftId, $sellerToken);
assertCondition("Draft product has status 'draft'", ($draftDetail['data']['data']['status'] ?? '') === 'draft');

// 2. Verify Customer Catalog hides DRAFT product
$custDraftList = getJson($baseUrl . '/api/v1/customer/products?search=Gaming+Mousepad+RGB');
$custItems = $custDraftList['data']['data']['items'] ?? [];
$foundInCatalog = false;
foreach ($custItems as $ci) {
    if ((int)$ci['id'] === (int)$draftId) { $foundInCatalog = true; break; }
}
assertCondition("Customer catalog list excludes draft product", !$foundInCatalog);

$custDraftGet = getJson($baseUrl . '/api/v1/customer/products/' . $draftId);
assertCondition("Customer direct GET on draft product returns 404 Not Found", $custDraftGet['status'] === 404);

// 3. Seller submits product for review (status: pending_review)
$submitPayload = [
    'title' => 'Test HyperX Studio Mechanical Numpad',
    'category_id' => 6,
    'description' => 'Dedicated hot-swappable mechanical numpad with aluminum case.',
    'price' => 3200.00,
    'mrp' => 3800.00,
    'stock_quantity' => 15,
    'status' => 'pending_review',
];
$pendingRes = postJson($baseUrl . '/api/v1/buddy/products', $submitPayload, $sellerToken);
assertCondition("Seller submits product for review (201 Created)", $pendingRes['status'] === 201);
$pendingId = $pendingRes['data']['data']['id'] ?? null;

$pendingDetail = getJson($baseUrl . '/api/v1/buddy/products/' . $pendingId, $sellerToken);
assertCondition("Submitted product has status 'pending_review'", ($pendingDetail['data']['data']['status'] ?? '') === 'pending_review');

// 4. Verify Customer Catalog hides PENDING_REVIEW product
$custPendingGet = getJson($baseUrl . '/api/v1/customer/products/' . $pendingId);
assertCondition("Customer catalog hides pending_review product (returns 404)", $custPendingGet['status'] === 404);

// 5. RBAC Protection on Admin Review Endpoint
// Seller attempts to call review endpoint
$sellerReviewAttempt = postJson($baseUrl . '/api/v1/buddy/products/' . $pendingId . '/review', [
    'action' => 'approve'
], $sellerToken);
assertCondition("Seller cannot perform admin review action (403 Forbidden)", $sellerReviewAttempt['status'] === 403);

// Unauthenticated attempt
$anonReviewAttempt = postJson($baseUrl . '/api/v1/buddy/products/' . $pendingId . '/review', [
    'action' => 'approve'
]);
assertCondition("Unauthenticated review attempt returns 401 Unauthorized", $anonReviewAttempt['status'] === 401);

// 6. Admin Rejects Product with Feedback
$rejectRes = postJson($baseUrl . '/api/v1/buddy/products/' . $pendingId . '/review', [
    'action' => 'reject',
    'notes' => 'Trade certification missing. Please provide manufacturer warranty documentation.'
], $adminToken);
assertCondition("Admin rejects product with feedback (200 OK)", $rejectRes['status'] === 200);

// Verify Seller sees Rejection status and feedback notes
$sellerViewRejected = getJson($baseUrl . '/api/v1/buddy/products/' . $pendingId, $sellerToken);
assertCondition("Product status updated to 'rejected'", ($sellerViewRejected['data']['data']['status'] ?? '') === 'rejected');
assertCondition("Review feedback notes visible to seller",
    strpos($sellerViewRejected['data']['data']['review_notes'] ?? '', 'Trade certification missing') !== false,
    "Notes: " . ($sellerViewRejected['data']['data']['review_notes'] ?? '')
);

// 7. Verify Customer Catalog hides REJECTED product
$custRejectedGet = getJson($baseUrl . '/api/v1/customer/products/' . $pendingId);
assertCondition("Customer catalog hides rejected product (returns 404)", $custRejectedGet['status'] === 404);

// 8. Seller updates rejected product (resubmits for review)
$resubmitRes = putJson($baseUrl . '/api/v1/buddy/products/' . $pendingId, [
    'description' => 'Dedicated hot-swappable mechanical numpad. Attached manufacturer trade warranty doc.',
    'status' => 'pending_review'
], $sellerToken);
assertCondition("Seller resubmits rejected product (200 OK)", $resubmitRes['status'] === 200);

$resubmittedDetail = getJson($baseUrl . '/api/v1/buddy/products/' . $pendingId, $sellerToken);
assertCondition("Product re-enters 'pending_review' status", ($resubmittedDetail['data']['data']['status'] ?? '') === 'pending_review');

// 9. Admin Approves Product
$approveRes = postJson($baseUrl . '/api/v1/buddy/products/' . $pendingId . '/review', [
    'action' => 'approve',
    'notes' => 'Manufacturer warranty verified. Listing approved for catalog.'
], $adminToken);
assertCondition("Admin approves product (200 OK)", $approveRes['status'] === 200);

$approvedDetail = getJson($baseUrl . '/api/v1/buddy/products/' . $pendingId, $sellerToken);
assertCondition("Product status updated to 'approved'", ($approvedDetail['data']['data']['status'] ?? '') === 'approved');

// 10. Seller publishes the approved product
$publishRes = putJson($baseUrl . '/api/v1/buddy/products/' . $pendingId, [
    'status' => 'published'
], $sellerToken);
assertCondition("Seller publishes approved product (200 OK)", $publishRes['status'] === 200);

// 11. Customer Catalog now displays the published product
$custPublishedGet = getJson($baseUrl . '/api/v1/customer/products/' . $pendingId);
assertCondition("Published product is accessible in customer catalog (200 OK)", $custPublishedGet['status'] === 200);
assertCondition("Customer catalog displays correct product title",
    ($custPublishedGet['data']['data']['title'] ?? '') === 'Test HyperX Studio Mechanical Numpad'
);

// 12. Cleanup temporary test products to maintain clean state
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();
$pdo->exec("DELETE FROM products WHERE id IN ($draftId, $pendingId)");

echo "\n======================================================\n";
echo "SUMMARY: Passed $passed of $total tests.\n";
echo "======================================================\n";
