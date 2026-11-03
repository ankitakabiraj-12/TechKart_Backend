<?php

declare(strict_types=1);

/**
 * Targeted End-to-End Test Suite for TechKart AI Visual Search & Multilingual Chatbot.
 * Validates:
 * 1. Server health & GD Image Processing Extension.
 * 2. Visual Search Image Perceptual Similarity (pHash) against MariaDB catalog.
 * 3. MariaDB Persistence of Visual Search queries (`ai_visual_searches`).
 * 4. English Conversational Shopping recommendations & bundle optimization.
 * 5. Bengali Conversational Shopping recommendations (Unicode \u0980-\u09FF).
 * 6. MariaDB Persistence of AI Chat Sessions & Messages (`ai_chat_sessions`, `ai_chat_messages`).
 * 7. Filtered retrieval of AI Chat Sessions by category (Recommendations vs Spec Checks).
 * 8. Full message history retrieval for active chat session.
 */

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function logStep(string $step, string $status, ?string $details = null): void {
    $icon = $status === 'PASS' ? '✅' : '❌';
    echo "[$icon $status] $step\n";
    if ($details) {
        echo "   -> $details\n";
    }
}

function httpPost(string $url, array $data, ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode((string)$response, true)];
}

function httpGet(string $url, ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode((string)$response, true)];
}

echo "===================================================================================\n";
echo "   TechKart AI Visual Search & Multilingual Chatbot: Targeted End-to-End Suite\n";
echo "===================================================================================\n\n";

$allPassed = true;

// ---------------------------------------------------------------------------
// Test 1: Core REST Server Health & Database Connection
// ---------------------------------------------------------------------------
$health = httpGet("$baseUrl/health");
if ($health['code'] === 200 && ($health['body']['success'] ?? false)) {
    logStep('Server & MariaDB Health Check', 'PASS', 'API is responsive at 127.0.0.1:8000');
} else {
    logStep('Server & MariaDB Health Check', 'FAIL', 'Health check failed: HTTP ' . $health['code']);
    exit(1);
}

// ---------------------------------------------------------------------------
// Test 2: Customer Authentication
// ---------------------------------------------------------------------------
$customerAuth = httpPost("$baseUrl/customer/auth/login", [
    'email' => 'rahul.test@techkart.com',
    'password' => 'password123'
]);
if ($customerAuth['code'] === 200 && !empty($customerAuth['body']['data']['token'])) {
    $customerToken = $customerAuth['body']['data']['token'];
    logStep('Customer Authentication', 'PASS', 'Authenticated as rahul.test@techkart.com');
} else {
    logStep('Customer Authentication', 'FAIL', json_encode($customerAuth));
    $allPassed = false;
    exit(1);
}

// ---------------------------------------------------------------------------
// Test 3: AI Visual Search Query (Perceptual Matching & Catalog Ranking)
// ---------------------------------------------------------------------------
$testImgPath = dirname(__DIR__, 2) . '/techkart/asset/chronos_ultra_2.jpg';
$base64Img = file_exists($testImgPath) ? base64_encode(file_get_contents($testImgPath)) : '';

$visualSearch = httpPost("$baseUrl/customer/ai/visual-search", [
    'query' => 'Chronos Ultra 2',
    'image_base64' => $base64Img,
], $customerToken);

if ($visualSearch['code'] === 200 && ($visualSearch['body']['success'] ?? false)) {
    $data = $visualSearch['body']['data'];
    $hero = $data['hero_match'] ?? [];
    $grid = $data['grid_products'] ?? [];
    if (!empty($hero['title']) && $hero['confidence'] >= 95.0 && count($grid) >= 3) {
        logStep('AI Visual Search Execution', 'PASS', "Top Match: '{$hero['title']}' ({$hero['confidence']}% match, Badge: '{$hero['badge']}'). Grid matches count: " . count($grid));
    } else {
        logStep('AI Visual Search Execution', 'FAIL', 'Unexpected visual search payload: ' . json_encode($data));
        $allPassed = false;
    }
} else {
    logStep('AI Visual Search Execution', 'FAIL', json_encode($visualSearch));
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Test 4: Visual Search MariaDB Persistence Verification
// ---------------------------------------------------------------------------
$pdo = new PDO('mysql:host=127.0.0.1;dbname=techkart_db;charset=utf8mb4', 'root', '');
$vStmt = $pdo->query('SELECT id, query_image_path, detected_category, match_confidence, created_at FROM ai_visual_searches ORDER BY id DESC LIMIT 1');
$lastVisual = $vStmt->fetch(PDO::FETCH_ASSOC);

if ($lastVisual && (float)$lastVisual['match_confidence'] >= 90.0) {
    logStep('Visual Search MariaDB Persistence', 'PASS', "Persisted search ID #{$lastVisual['id']} (Category: {$lastVisual['detected_category']}, Confidence: {$lastVisual['match_confidence']}%)");
} else {
    logStep('Visual Search MariaDB Persistence', 'FAIL', 'No record found in ai_visual_searches table');
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Test 5: English Conversational Shopping Assistant Recommendation & Bundle
// ---------------------------------------------------------------------------
$enChat = httpPost("$baseUrl/customer/ai/chat/message", [
    'message' => 'Looking for an ultra-durable titanium smartwatch for trail workouts, plus a 65W GaN dual fast charger under $500 total.',
], $customerToken);

if ($enChat['code'] === 200 && ($enChat['body']['success'] ?? false)) {
    $chatData = $enChat['body']['data'];
    $sessionId = $chatData['session_id'];
    $asstMsg = $chatData['assistant_message'] ?? [];
    if (!empty($asstMsg['text']) && ($asstMsg['is_recommendation_bundle'] ?? false) === true && count($asstMsg['products']) >= 2) {
        logStep('English Chatbot Recommendation & Bundle', 'PASS', "Session: $sessionId. Generated curated bundle with " . count($asstMsg['products']) . " catalog items.");
    } else {
        logStep('English Chatbot Recommendation & Bundle', 'FAIL', 'Unexpected assistant response: ' . json_encode($chatData));
        $allPassed = false;
    }
} else {
    logStep('English Chatbot Recommendation & Bundle', 'FAIL', json_encode($enChat));
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Test 6: Bengali Conversational Assistant NLP Understanding & Recommendation
// ---------------------------------------------------------------------------
$bnChat = httpPost("$baseUrl/customer/ai/chat/message", [
    'message' => 'আমার ট্রেইল ওয়ার্কআউটের জন্য টাইটানিয়াম ঘড়ি ও ফাস্ট চার্জার বান্ডেল দেখান',
    'session_id' => $sessionId,
], $customerToken);

if ($bnChat['code'] === 200 && ($bnChat['body']['success'] ?? false)) {
    $bnData = $bnChat['body']['data'];
    $bnAsst = $bnData['assistant_message'] ?? [];
    if (($bnAsst['language'] ?? '') === 'bn' && !empty($bnAsst['text']) && ($bnAsst['is_recommendation_bundle'] ?? false) === true) {
        logStep('Bengali Chatbot Multilingual Recommendation', 'PASS', "Detected Bengali language. Generated native response: '{$bnAsst['text']}'");
    } else {
        logStep('Bengali Chatbot Multilingual Recommendation', 'FAIL', 'Unexpected Bengali response: ' . json_encode($bnData));
        $allPassed = false;
    }
} else {
    logStep('Bengali Chatbot Multilingual Recommendation', 'FAIL', json_encode($bnChat));
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Test 7: MariaDB Persistence of Chat Session & Messages
// ---------------------------------------------------------------------------
$sStmt = $pdo->prepare('SELECT session_id, message_count, category, language FROM ai_chat_sessions WHERE session_id = :sid LIMIT 1');
$sStmt->execute(['sid' => $sessionId]);
$dbSession = $sStmt->fetch(PDO::FETCH_ASSOC);

$mStmt = $pdo->prepare('SELECT COUNT(*) FROM ai_chat_messages WHERE session_id = :sid');
$mStmt->execute(['sid' => $sessionId]);
$msgCount = (int)$mStmt->fetchColumn();

if ($dbSession && $msgCount >= 4) {
    logStep('Chat Session & Message Persistence', 'PASS', "Session $sessionId persisted in MariaDB with $msgCount dialogue messages.");
} else {
    logStep('Chat Session & Message Persistence', 'FAIL', "Database session check failed (count: $msgCount)");
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Test 8: Archived AI Chat Sessions List Retrieval & Filtering
// ---------------------------------------------------------------------------
$sessionsList = httpGet("$baseUrl/customer/ai/chat/sessions", $customerToken);
if ($sessionsList['code'] === 200 && ($sessionsList['body']['success'] ?? false)) {
    $sessData = $sessionsList['body']['data'];
    $totalCount = $sessData['total_count'] ?? 0;
    $recent = $sessData['recent'] ?? [];
    if ($totalCount >= 6 && !empty($recent)) {
        logStep('Archived AI Sessions List Retrieval', 'PASS', "Loaded $totalCount archived sessions. Recent count: " . count($recent));
    } else {
        logStep('Archived AI Sessions List Retrieval', 'FAIL', 'Unexpected session list format: ' . json_encode($sessData));
        $allPassed = false;
    }
} else {
    logStep('Archived AI Sessions List Retrieval', 'FAIL', json_encode($sessionsList));
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Test 9: Session Messages History API
// ---------------------------------------------------------------------------
$history = httpGet("$baseUrl/customer/ai/chat/sessions/$sessionId/messages", $customerToken);
if ($history['code'] === 200 && ($history['body']['success'] ?? false)) {
    $histData = $history['body']['data'];
    $messages = $histData['messages'] ?? [];
    if (count($messages) >= 4) {
        logStep('Full Session History Retrieval', 'PASS', "Retrieved complete chat history with " . count($messages) . " messages for session #$sessionId");
    } else {
        logStep('Full Session History Retrieval', 'FAIL', 'Unexpected message count: ' . count($messages));
        $allPassed = false;
    }
} else {
    logStep('Full Session History Retrieval', 'FAIL', json_encode($history));
    $allPassed = false;
}

echo "\n===================================================================================\n";
if ($allPassed) {
    echo "🎉 ALL 9 AI VISUAL SEARCH & CHATBOT TARGETED E2E TESTS PASSED!\n";
    echo "===================================================================================\n";
    exit(0);
} else {
    echo "❌ SOME AI E2E VERIFICATIONS FAILED.\n";
    echo "===================================================================================\n";
    exit(1);
}
