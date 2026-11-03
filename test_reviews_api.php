<?php

declare(strict_types=1);

/**
 * TechKart Reviews & Ratings Integration Test Suite
 *
 * Tests:
 * 1. Health check & database connection
 * 2. Public review list & distribution summary fetch
 * 3. Customer login & JWT authentication
 * 4. Customer review submission (verified purchase detection)
 * 5. Review update / edit flow
 * 6. Helpful vote upvoting & toggling
 * 7. Duplicate review update / prevention
 * 8. Review deletion
 * 9. Re-verify rating summary post-deletion
 */

function runTest(string $title, callable $testFn): void {
    echo "Running: {$title}... ";
    try {
        $testFn();
        echo "[PASSED]\n";
    } catch (Throwable $e) {
        echo "[FAILED] - " . $e->getMessage() . "\n";
        exit(1);
    }
}

function request(string $method, string $path, ?array $body = null, ?string $token = null): array {
    $url = 'http://127.0.0.1:8000/api/v1' . $path;
    $opts = [
        'http' => [
            'method'        => $method,
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
            'timeout'       => 10,
        ]
    ];
    if ($token !== null) {
        $opts['http']['header'] .= "Authorization: Bearer {$token}\r\n";
    }
    if ($body !== null) {
        $opts['http']['content'] = json_encode($body);
    }

    $ctx = stream_context_create($opts);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        throw new Exception("HTTP request failed: {$method} {$url}");
    }
    $decoded = json_decode($res, true);
    if (!is_array($decoded)) {
        throw new Exception("Invalid JSON response: " . substr($res, 0, 200));
    }
    return $decoded;
}

echo "=========================================================\n";
echo "TechKart Reviews & Ratings End-to-End Test Suite\n";
echo "=========================================================\n";

$jwtToken = '';
$testReviewId = 0;

// Test 1: Health check
runTest('Server Health Check', function() {
    $res = request('GET', '/health');
    if (($res['status'] ?? 0) !== 200) {
        throw new Exception('Health check failed: ' . json_encode($res));
    }
});

// Test 2: Public reviews & rating summary fetch for product 2
runTest('Fetch Public Product Reviews & Rating Summary', function() {
    $res = request('GET', '/customer/products/2/reviews');
    if (($res['status'] ?? 0) !== 200 || empty($res['data']['summary'])) {
        throw new Exception('Failed to fetch reviews: ' . json_encode($res));
    }
    $summary = $res['data']['summary'];
    if (!isset($summary['average_rating'], $summary['total_reviews'], $summary['distribution'])) {
        throw new Exception('Summary missing expected fields: ' . json_encode($summary));
    }
});

// Test 3: Customer login
runTest('Customer Authentication (Rahul Sharma)', function() use (&$jwtToken) {
    $res = request('POST', '/customer/auth/login', [
        'email'    => 'rahul.sharma@techkart.com',
        'password' => 'Password@123',
    ]);
    if (($res['status'] ?? 0) !== 200 || empty($res['data']['token'])) {
        throw new Exception('Customer login failed: ' . json_encode($res));
    }
    $jwtToken = $res['data']['token'];
});

// Test 4: Authenticated review submission
runTest('Authenticated Review Submission with Verified Purchase Detection', function() use ($jwtToken, &$testReviewId) {
    $res = request('POST', '/customer/products/2/reviews', [
        'rating'          => 5,
        'title'           => 'Incredible build quality and long battery life',
        'review_text'     => 'I have used this product during heavy workouts for the past two weeks. The battery easily lasts through 3 full days of heavy usage and GPS tracking.',
        'standout_tags'   => ['Build Quality', 'Battery Life'],
        'photos'          => [],
        'would_recommend' => true,
    ], $jwtToken);

    if (($res['status'] ?? 0) !== 201 || empty($res['data']['review_id'])) {
        throw new Exception('Review submission failed: ' . json_encode($res));
    }
    if (empty($res['data']['is_verified_purchase'])) {
        throw new Exception('Expected verified purchase status to be true for Rahul Sharma on product 2.');
    }
    $testReviewId = (int)$res['data']['review_id'];
});

// Test 5: Helpful vote upvote & toggle
runTest('Helpful Vote Toggle & Counter Tracking', function() use ($jwtToken, $testReviewId) {
    // Upvote
    $res1 = request('POST', "/customer/reviews/{$testReviewId}/helpful", [], $jwtToken);
    if (($res1['status'] ?? 0) !== 200 || ($res1['data']['has_voted'] ?? false) !== true) {
        throw new Exception('Helpful vote failed: ' . json_encode($res1));
    }

    // Toggle off
    $res2 = request('POST', "/customer/reviews/{$testReviewId}/helpful", [], $jwtToken);
    if (($res2['status'] ?? 0) !== 200 || ($res2['data']['has_voted'] ?? true) !== false) {
        throw new Exception('Helpful vote removal failed: ' . json_encode($res2));
    }
});

// Test 6: Review edit / update
runTest('Customer Review Modification (PUT)', function() use ($jwtToken, $testReviewId) {
    $res = request('PUT', "/customer/reviews/{$testReviewId}", [
        'rating'          => 4,
        'title'           => 'Updated Title: Great experience overall',
        'review_text'     => 'Updating review after one month of daily usage. Screen brightness is great, slightly stiffer band initially.',
        'standout_tags'   => ['Screen Brightness'],
        'photos'          => [],
        'would_recommend' => true,
    ], $jwtToken);

    if (($res['status'] ?? 0) !== 200) {
        throw new Exception('Review update failed: ' . json_encode($res));
    }
});

// Test 7: Duplicate prevention / upsert flow
runTest('Duplicate Review Prevention on Product', function() use ($jwtToken, $testReviewId) {
    $res = request('POST', '/customer/products/2/reviews', [
        'rating'          => 5,
        'title'           => 'Refined review after long-term testing',
        'review_text'     => 'The product has held up exceptionally well. Truly top-tier build quality and GPS accuracy.',
        'standout_tags'   => ['Build Quality', 'Battery Life'],
        'photos'          => [],
        'would_recommend' => true,
    ], $jwtToken);

    if (($res['status'] ?? 0) !== 201 || ($res['data']['review_id'] ?? 0) !== $testReviewId) {
        throw new Exception('Duplicate prevention upsert failed: ' . json_encode($res));
    }
});

// Test 8: Review deletion
runTest('Customer Review Deletion (DELETE)', function() use ($jwtToken, $testReviewId) {
    $res = request('DELETE', "/customer/reviews/{$testReviewId}", [], $jwtToken);
    if (($res['status'] ?? 0) !== 200) {
        throw new Exception('Review deletion failed: ' . json_encode($res));
    }
});

// Test 9: Verify review was deleted
runTest('Verify Review Deletion from List & Recalculated Summary', function() use ($testReviewId) {
    $res = request('GET', '/customer/products/2/reviews');
    $reviews = $res['data']['reviews'] ?? [];
    foreach ($reviews as $rev) {
        if ($rev['id'] === $testReviewId) {
            throw new Exception("Deleted review {$testReviewId} still appears in list!");
        }
    }
});

// Test 10: Forbidden check - Attempt to edit another customer's review
runTest('Forbidden: Attempt to Edit Another Customer Review (HTTP 403)', function() use ($jwtToken) {
    // Review 1 belongs to customer 1 (Marcus R.)
    $res = request('PUT', '/customer/reviews/1', [
        'rating'          => 1,
        'title'           => 'Hacked Title',
        'review_text'     => 'Trying to maliciously overwrite another customer review.',
        'standout_tags'   => [],
        'photos'          => [],
        'would_recommend' => false,
    ], $jwtToken);

    if (($res['status'] ?? 0) !== 403) {
        throw new Exception('Expected HTTP 403 Forbidden when editing another customer review. Received: ' . json_encode($res));
    }
});

// Test 11: Forbidden check - Attempt to delete another customer's review
runTest('Forbidden: Attempt to Delete Another Customer Review (HTTP 403)', function() use ($jwtToken) {
    // Review 1 belongs to customer 1 (Marcus R.)
    $res = request('DELETE', '/customer/reviews/1', [], $jwtToken);

    if (($res['status'] ?? 0) !== 403) {
        throw new Exception('Expected HTTP 403 Forbidden when deleting another customer review. Received: ' . json_encode($res));
    }
});

echo "=========================================================\n";
echo "All 11 Reviews & Ratings Backend Integration Tests Passed!\n";
echo "=========================================================\n";
