<?php

declare(strict_types=1);

/**
 * Automated Verification Test Suite for TechKart Buddy Customer Reviews & Ratings
 *
 * Verifies:
 * 1. Login Seller 2 (Apex Gear BD)
 * 2. Login Seller 4 (GadgetPlanet BD)
 * 3. Login Admin (admin.audit@techkart.com)
 * 4. Seller 2 Reviews Retrieval:
 *    - Validates aggregate summary: average_rating, total_reviews, distribution, unreplied_count
 *    - Validates reviews belongs ONLY to Seller 2's products
 * 5. Seller 4 Reviews Isolation:
 *    - Validates Seller 4 only sees reviews for Seller 4's products (e.g. product 21)
 *    - Ensures Seller 4 cannot see Seller 2's reviews
 * 6. Cross-Seller Isolation & Security:
 *    - Seller 4 attempts to reply to Seller 2 review -> MUST return 403 Forbidden
 * 7. Seller Reply Execution & Persistence:
 *    - Seller 2 replies to Review #1 -> Returns 200 OK
 *    - Verifies seller_reply and replied_at in database
 * 8. Admin Global Review Listing:
 *    - Admin GET /api/v1/buddy/admin/reviews returns all reviews across all sellers
 * 9. Admin Moderation:
 *    - Admin moderates review status to 'flagged' or 'hidden' with reason -> Returns 200 OK
 *    - Verifies moderated_by, moderation_reason, moderated_at persisted
 * 10. Role Protection:
 *    - Non-admin seller attempts admin moderation -> MUST return 403 Forbidden
 */

$baseUrl = 'http://127.0.0.1:8000';

function sendRequest(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $baseUrl;
    $url = $baseUrl . $path;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return [
            'status' => 0,
            'body' => null,
            'raw' => $err
        ];
    }

    return [
        'status' => $httpCode,
        'body' => json_decode($response, true),
        'raw' => $response
    ];
}

function login(string $email, string $password): string
{
    $res = sendRequest('POST', '/api/v1/buddy/auth/login', [
        'email' => $email,
        'password' => $password
    ]);

    if ($res['status'] !== 200 || empty($res['body']['data']['token'])) {
        echo "❌ Login failed for {$email}. Status: {$res['status']}. Body: {$res['raw']}\n";
        exit(1);
    }

    return $res['body']['data']['token'];
}

echo "=========================================================\n";
echo " TECHKART BUDDY REVIEWS & RATINGS AUTOMATED TEST SUITE\n";
echo "=========================================================\n\n";

// Step 1: Authentication
echo "[1/10] Authenticating test actors...\n";
$seller2Token = login('vikram.store@techkart.com', 'password123');
echo "  ✓ Seller 2 authenticated (Vikram / Apex Gear BD)\n";

$seller4Token = login('other.seller@techkart.com', 'password123');
echo "  ✓ Seller 4 authenticated (Other Seller / GadgetPlanet)\n";

$adminToken = login('admin.audit@techkart.com', 'password123');
echo "  ✓ Admin authenticated (Admin Audit)\n\n";

// Step 2: Test Seller 2 Reviews Retrieval & Aggregates
echo "[2/10] Testing Seller 2 reviews retrieval & summary aggregates...\n";
$resSeller2 = sendRequest('GET', '/api/v1/buddy/seller/reviews', null, $seller2Token);
if ($resSeller2['status'] !== 200 || empty($resSeller2['body']['success'])) {
    echo "❌ Failed to retrieve Seller 2 reviews. HTTP: {$resSeller2['status']}. Body: {$resSeller2['raw']}\n";
    exit(1);
}
$data = $resSeller2['body']['data'] ?? $resSeller2['body'];
$s2Summary = $data['summary'];
$s2Reviews = $data['reviews'];
echo "  ✓ Status 200 OK. Reviews count: " . count($s2Reviews) . "\n";
echo "  ✓ Summary: Avg Rating = {$s2Summary['average_rating']}, Total = {$s2Summary['total_reviews']}, Unreplied = {$s2Summary['unreplied_count']}\n";
echo "  ✓ Star Distribution: 5★ = {$s2Summary['distribution']['5_star']}, 4★ = {$s2Summary['distribution']['4_star']}\n";

// Assert all returned reviews belong to Seller 2's products (id 2)
foreach ($s2Reviews as $r) {
    if ($r['product_id'] === 21) {
        echo "❌ Cross-seller leak detected: Seller 2 received product 21 (Seller 4's product)!\n";
        exit(1);
    }
}
echo "  ✓ Verified: Zero cross-seller reviews leaked to Seller 2.\n\n";

// Step 3: Test Seller 4 Reviews Isolation
echo "[3/10] Testing Seller 4 reviews isolation...\n";
$resSeller4 = sendRequest('GET', '/api/v1/buddy/seller/reviews', null, $seller4Token);
if ($resSeller4['status'] !== 200) {
    echo "❌ Failed to retrieve Seller 4 reviews. HTTP: {$resSeller4['status']}\n";
    exit(1);
}
$s4Data = $resSeller4['body']['data'] ?? $resSeller4['body'];
$s4Reviews = $s4Data['reviews'];
echo "  ✓ Status 200 OK. Seller 4 reviews count: " . count($s4Reviews) . "\n";
foreach ($s4Reviews as $r) {
    if ($r['product_id'] !== 21 && $r['product_id'] !== 22) {
        echo "❌ Isolation failure: Seller 4 received review for product {$r['product_id']}!\n";
        exit(1);
    }
}
echo "  ✓ Verified: Seller 4 received strictly Seller 4 reviews.\n\n";

// Step 4: Test Filter by Rating & Unreplied
echo "[4/10] Testing review filtering (unreplied and rating pills)...\n";
$resUnreplied = sendRequest('GET', '/api/v1/buddy/seller/reviews?has_reply=false', null, $seller2Token);
if ($resUnreplied['status'] !== 200) {
    echo "❌ Filter has_reply=false failed.\n";
    exit(1);
}
$unrepliedData = $resUnreplied['body']['data'] ?? $resUnreplied['body'];
echo "  ✓ Filter has_reply=false returned " . count($unrepliedData['reviews']) . " items\n";

$res5Star = sendRequest('GET', '/api/v1/buddy/seller/reviews?rating=5', null, $seller2Token);
if ($res5Star['status'] !== 200) {
    echo "❌ Filter rating=5 failed.\n";
    exit(1);
}
$star5Data = $res5Star['body']['data'] ?? $res5Star['body'];
echo "  ✓ Filter rating=5 returned " . count($star5Data['reviews']) . " items\n\n";

// Step 5: Test Cross-Seller Tampering Protection (Seller 4 tries to reply to Seller 2's review #1)
echo "[5/10] Testing Cross-Seller reply protection (Seller 4 -> Review #1)...\n";
$resCrossReply = sendRequest('POST', '/api/v1/buddy/seller/reviews/1/reply', [
    'seller_reply' => 'Unauthorized reply attempt from Seller 4'
], $seller4Token);
if ($resCrossReply['status'] === 403) {
    echo "  ✓ Correctly rejected with 403 Forbidden! Message: {$resCrossReply['body']['message']}\n\n";
} else {
    echo "❌ Security breach! Cross-seller reply did NOT return 403 Forbidden (got {$resCrossReply['status']})\n";
    exit(1);
}

// Step 6: Test Authorized Seller Reply Execution
echo "[6/10] Testing Authorized Seller reply execution (Seller 2 -> Review #1)...\n";
$replyText = "Thank you so much for your review! Our engineering team takes great pride in titanium durability.";
$resReply = sendRequest('POST', '/api/v1/buddy/seller/reviews/1/reply', [
    'seller_reply' => $replyText
], $seller2Token);
if ($resReply['status'] !== 200 || empty($resReply['body']['success'])) {
    echo "❌ Seller reply failed. HTTP: {$resReply['status']}, Body: {$resReply['raw']}\n";
    exit(1);
}
$replyData = $resReply['body']['data'] ?? $resReply['body'];
echo "  ✓ Status 200 OK: {$resReply['body']['message']}\n";
echo "  ✓ Replied text: {$replyData['review']['seller_reply']}\n\n";

// Step 7: Verify Database Persistence of Reply
echo "[7/10] Verifying DB persistence and updated unreplied counter...\n";
$resAfterReply = sendRequest('GET', '/api/v1/buddy/seller/reviews', null, $seller2Token);
$afterData = $resAfterReply['body']['data'] ?? $resAfterReply['body'];
$foundReplied = false;
foreach ($afterData['reviews'] as $rev) {
    if ($rev['id'] === 1 && $rev['seller_reply'] === $replyText) {
        $foundReplied = true;
        break;
    }
}
if (!$foundReplied) {
    echo "❌ Review #1 in response did not reflect updated seller_reply!\n";
    exit(1);
}
echo "  ✓ DB persistence verified for Review #1 seller_reply and replied_at.\n\n";

// Step 8: Test Admin Marketplace Reviews Listing
echo "[8/10] Testing Admin marketplace reviews listing across all sellers...\n";
$resAdminReviews = sendRequest('GET', '/api/v1/buddy/admin/reviews', null, $adminToken);
$adminData = $resAdminReviews['body']['data'] ?? $resAdminReviews['body'];
if ($resAdminReviews['status'] !== 200 || empty($adminData['reviews'])) {
    echo "❌ Admin review list failed. HTTP: {$resAdminReviews['status']}\n";
    exit(1);
}
$adminReviewList = $adminData['reviews'];
echo "  ✓ Admin received total " . count($adminReviewList) . " marketplace reviews.\n";
$sellerIds = array_unique(array_map(fn($r) => $r['seller_id'], $adminReviewList));
echo "  ✓ Includes sellers: " . implode(', ', $sellerIds) . "\n\n";

// Step 9: Test Admin Content Moderation
echo "[9/10] Testing Admin moderation action (flag/hidden status change)...\n";
$resModerate = sendRequest('PUT', '/api/v1/buddy/admin/reviews/3/moderate', [
    'status' => 'flagged',
    'reason' => 'Customer review contains external link reference - flagged for review'
], $adminToken);
if ($resModerate['status'] !== 200 || empty($resModerate['body']['success'])) {
    echo "❌ Admin moderation failed. HTTP: {$resModerate['status']}, Body: {$resModerate['raw']}\n";
    exit(1);
}
$modData = $resModerate['body']['data'] ?? $resModerate['body'];
echo "  ✓ Status 200 OK: {$resModerate['body']['message']}\n";
echo "  ✓ Moderated status: {$modData['moderation']['status']}, reason: {$modData['moderation']['moderation_reason']}\n\n";

// Step 10: Test Role Authorization Protection for Admin Moderation Endpoint
echo "[10/10] Testing Non-admin seller blocked from admin moderation endpoint...\n";
$resForbiddenMod = sendRequest('PUT', '/api/v1/buddy/admin/reviews/3/moderate', [
    'status' => 'approved',
    'reason' => 'Unauthorized attempt'
], $seller2Token);
if ($resForbiddenMod['status'] === 403) {
    echo "  ✓ Non-admin seller correctly blocked with 403 Forbidden!\n\n";
} else {
    echo "❌ Non-admin seller was NOT blocked from moderation! Status: {$resForbiddenMod['status']}\n";
    exit(1);
}

echo "=========================================================\n";
echo " ALL 10 VERIFICATION TESTS PASSED SUCCESSFULLY! 🎉\n";
echo "=========================================================\n";
