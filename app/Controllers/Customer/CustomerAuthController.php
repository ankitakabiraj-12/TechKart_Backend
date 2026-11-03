<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Core\Database;
use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use App\Services\MailService;
use PDO;
use Throwable;

/**
 * Controller handling Customer (TechKart App) Authentication
 */
class CustomerAuthController
{
    /**
     * Customer Registration
     * POST /api/v1/customer/auth/register
     * Body: { full_name, email, password, phone? }
     */
    public function register(Request $request): void
    {
        $fullName = trim((string)$request->get('full_name', ''));
        $email    = strtolower(trim((string)$request->get('email', '')));
        $password = (string)$request->get('password', '');
        $phone    = trim((string)$request->get('phone', '')) ?: null;
        $genderRaw = strtolower(trim((string)$request->get('gender', '')));
        $gender   = in_array($genderRaw, ['male', 'female', 'other'], true) ? $genderRaw : null;

        // Validation
        $errors = [];
        if (mb_strlen($fullName) < 2) {
            $errors['full_name'] = 'Full name is required and must be at least 2 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        if ($phone !== null && !preg_match('/^[0-9+\-\s]{7,25}$/', $phone)) {
            $errors['phone'] = 'Invalid phone number format.';
        }

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $pdo = Database::getConnection();

            // Check if email already exists
            $checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $checkStmt->execute(['email' => $email]);
            if ($checkStmt->fetch()) {
                Response::error('Email is already registered. Please login instead.', 409, ['email' => 'Email already registered']);
            }

            // Check if phone already exists (if provided)
            if ($phone !== null) {
                $checkPhone = $pdo->prepare('SELECT id FROM users WHERE phone = :phone LIMIT 1');
                $checkPhone->execute(['phone' => $phone]);
                if ($checkPhone->fetch()) {
                    Response::error('Phone number is already associated with another account.', 409, ['phone' => 'Phone already in use']);
                }
            }

            // Secure password hash
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            // Rate limit check: maximum 1 registration OTP request per 60 seconds
            $recentStmt = $pdo->prepare(
                'SELECT created_at FROM registration_otps WHERE email = :email ORDER BY id DESC LIMIT 1'
            );
            $recentStmt->execute(['email' => $email]);
            $recent = $recentStmt->fetch(PDO::FETCH_ASSOC);
            if ($recent && !empty($recent['created_at'])) {
                $elapsed = time() - (int)strtotime((string)$recent['created_at']);
                if ($elapsed >= 0 && $elapsed < 60) {
                    Response::error('Please wait before requesting another code.', 429, [
                        'retry_after' => 60 - $elapsed,
                    ]);
                }
            }

            // Invalidate any prior active registration OTPs for this email
            $pdo->prepare('UPDATE registration_otps SET consumed_at = NOW() WHERE email = :email AND consumed_at IS NULL')
                ->execute(['email' => $email]);

            // Generate 6-digit cryptographic OTP
            $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

            $insertStmt = $pdo->prepare(
                'INSERT INTO registration_otps
                    (full_name, email, phone, gender, password_hash, otp_hash, expires_at, request_ip)
                 VALUES
                    (:full_name, :email, :phone, :gender, :password_hash, :otp_hash, :expires_at, :request_ip)'
            );
            $insertStmt->execute([
                'full_name'     => $fullName,
                'email'         => $email,
                'phone'         => $phone,
                'gender'        => $gender,
                'password_hash' => $passwordHash,
                'otp_hash'      => hash('sha256', $otp),
                'expires_at'    => $expiresAt,
                'request_ip'    => substr($request->getIp(), 0, 45),
            ]);

            // Attempt delivery if mail service is configured and enabled
            $mailConfigured = MailService::isConfigured();
            $emailSent = false;
            if ($mailConfigured) {
                try {
                    MailService::sendRegistrationOtp($email, $fullName, $otp, 10);
                    $emailSent = true;
                } catch (Throwable $e) {
                    error_log('[CustomerAuthController] Registration OTP email failed: ' . $e->getMessage());
                }
            }

            // Return clean staged registration response without creating active customer record
            $deliveryStatus = $mailConfigured ? ($emailSent ? 'sent' : 'failed') : 'delivery_not_configured';

            Response::json([
                'email'         => $email,
                'phone'         => $phone,
                'requires_otp'  => true,
                'expires_in'    => 600,
                'resend_after'  => 60,
                'delivery'      => $deliveryStatus,
            ], 200, 'Registration verification code initiated. Please verify the code to complete registration.');

        } catch (Throwable $e) {
            Response::error('Registration failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Resend Registration OTP
     * POST /api/v1/customer/auth/resend-register-otp
     * Body: { email }
     */
    public function resendRegisterOtp(Request $request): void
    {
        $email = strtolower(trim((string)$request->get('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('A valid email address is required.', 422, ['email' => 'Valid email required']);
        }

        try {
            $pdo = Database::getConnection();

            // Check if already registered
            $chk = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $chk->execute(['email' => $email]);
            if ($chk->fetch()) {
                Response::error('This account is already registered. Please login.', 409);
            }

            // Find pending registration
            $pendingStmt = $pdo->prepare(
                'SELECT * FROM registration_otps WHERE email = :email AND consumed_at IS NULL ORDER BY id DESC LIMIT 1'
            );
            $pendingStmt->execute(['email' => $email]);
            $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);

            if (!$pending) {
                Response::error('No pending registration found for this email. Please register again.', 404);
            }

            $elapsed = time() - (int)strtotime((string)$pending['created_at']);
            if ($elapsed >= 0 && $elapsed < 60) {
                Response::error('Please wait before requesting another code.', 429, [
                    'retry_after' => 60 - $elapsed,
                ]);
            }

            // Invalidate old OTP
            $pdo->prepare('UPDATE registration_otps SET consumed_at = NOW() WHERE id = :id')
                ->execute(['id' => (int)$pending['id']]);

            // Generate new OTP
            $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', time() + 600);

            $insertStmt = $pdo->prepare(
                'INSERT INTO registration_otps
                    (full_name, email, phone, gender, password_hash, otp_hash, expires_at, request_ip)
                 VALUES
                    (:full_name, :email, :phone, :gender, :password_hash, :otp_hash, :expires_at, :request_ip)'
            );
            $insertStmt->execute([
                'full_name'     => $pending['full_name'],
                'email'         => $pending['email'],
                'phone'         => $pending['phone'],
                'gender'        => $pending['gender'],
                'password_hash' => $pending['password_hash'],
                'otp_hash'      => hash('sha256', $otp),
                'expires_at'    => $expiresAt,
                'request_ip'    => substr($request->getIp(), 0, 45),
            ]);

            $mailConfigured = MailService::isConfigured();
            $emailSent = false;
            if ($mailConfigured) {
                try {
                    MailService::sendRegistrationOtp($email, (string)$pending['full_name'], $otp, 10);
                    $emailSent = true;
                } catch (Throwable $e) {
                    error_log('[CustomerAuthController] Resend OTP email failed: ' . $e->getMessage());
                }
            }

            $deliveryStatus = $mailConfigured ? ($emailSent ? 'sent' : 'failed') : 'delivery_not_configured';

            Response::json([
                'email'        => $email,
                'expires_in'   => 600,
                'resend_after' => 60,
                'delivery'     => $deliveryStatus,
            ], 200, 'A new verification code has been generated.');

        } catch (Throwable $e) {
            Response::error('Unable to resend verification code: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Verify Registration OTP and Create Active Customer Account
     * POST /api/v1/customer/auth/verify-register-otp
     * Body: { email, otp }
     */
    public function verifyRegisterOtp(Request $request): void
    {
        $email = strtolower(trim((string)$request->get('email', '')));
        $otp   = trim((string)$request->get('otp', ''));

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if (!preg_match('/^\d{6}$/', $otp)) {
            $errors['otp'] = 'Enter the 6-digit verification code.';
        }
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $pdo = Database::getConnection();

            // Check if user account was already created concurrently
            $checkExisting = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $checkExisting->execute(['email' => $email]);
            if ($checkExisting->fetch()) {
                Response::error('This account is already registered and active. Please login.', 409);
            }

            $stmt = $pdo->prepare(
                'SELECT * FROM registration_otps
                 WHERE email = :email
                   AND consumed_at IS NULL
                   AND verified_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $pending = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pending) {
                Response::error('The verification code is invalid or has expired.', 400);
            }

            // Check attempts
            if ((int)$pending['attempts'] >= 5) {
                $pdo->prepare('UPDATE registration_otps SET consumed_at = NOW() WHERE id = :id')
                    ->execute(['id' => (int)$pending['id']]);
                Response::error('Too many incorrect attempts. Please request a new code.', 429);
            }

            // Check expiration
            if (strtotime((string)$pending['expires_at']) < time()) {
                $pdo->prepare('UPDATE registration_otps SET consumed_at = NOW() WHERE id = :id')
                    ->execute(['id' => (int)$pending['id']]);
                Response::error('The verification code has expired. Please request a new code.', 400);
            }

            // Verify hash
            if (!hash_equals((string)$pending['otp_hash'], hash('sha256', $otp))) {
                $nextAttempts = (int)$pending['attempts'] + 1;
                $pdo->prepare(
                    'UPDATE registration_otps SET attempts = :att, consumed_at = :cons WHERE id = :id'
                )->execute([
                    'att'  => $nextAttempts,
                    'cons' => $nextAttempts >= 5 ? date('Y-m-d H:i:s') : null,
                    'id'   => (int)$pending['id'],
                ]);
                Response::error('Invalid verification code.', 400);
            }

            // Atomically create the user and mark OTP consumed
            $pdo->beginTransaction();

            $insertUser = $pdo->prepare(
                'INSERT INTO users (role, full_name, email, phone, gender, password_hash, is_active)
                 VALUES (:role, :full_name, :email, :phone, :gender, :password_hash, 1)'
            );
            $insertUser->execute([
                'role'          => 'customer',
                'full_name'     => $pending['full_name'],
                'email'         => $pending['email'],
                'phone'         => $pending['phone'],
                'gender'        => $pending['gender'],
                'password_hash' => $pending['password_hash'],
            ]);

            $userId = (int)$pdo->lastInsertId();

            // Mark registration OTP consumed
            $pdo->prepare('UPDATE registration_otps SET verified_at = NOW(), consumed_at = NOW() WHERE id = :id')
                ->execute(['id' => (int)$pending['id']]);

            // Invalidate any other pending registration OTPs for this email
            $pdo->prepare('UPDATE registration_otps SET consumed_at = NOW() WHERE email = :email AND id != :id')
                ->execute(['email' => $email, 'id' => (int)$pending['id']]);

            $pdo->commit();

            // Generate JWT Token
            $tokenPayload = [
                'sub'   => $userId,
                'email' => $email,
                'role'  => 'customer',
            ];
            $token = Jwt::generate($tokenPayload);
            $refreshToken = Jwt::createRefreshToken($userId, $request->getIp());

            $user = [
                'id'         => $userId,
                'role'       => 'customer',
                'full_name'  => $pending['full_name'],
                'email'      => $email,
                'phone'      => $pending['phone'],
                'gender'     => $pending['gender'],
                'avatar_url' => null,
            ];

            Response::json([
                'user'          => $user,
                'token'         => $token,
                'refresh_token' => $refreshToken,
                'token_type'    => 'Bearer',
            ], 201, 'Customer account verified and created successfully');

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Account activation failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Customer Login
     * POST /api/v1/customer/auth/login
     * Body: { email, password }
     */
    public function login(Request $request): void
    {
        $identifier = trim((string)$request->get('email', ''));
        if ($identifier === '') {
            $identifier = trim((string)$request->get('phone', ''));
        }
        $password = (string)$request->get('password', '');

        if (empty($identifier) || empty($password)) {
            Response::error('Invalid email/mobile number or password provided.', 422);
        }

        try {
            $pdo = Database::getConnection();

            // Support login by either email or phone number
            $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
            if ($isEmail) {
                $stmt = $pdo->prepare(
                    'SELECT id, role, full_name, email, phone, gender, avatar_url, password_hash, is_active
                     FROM users
                     WHERE email = :identifier
                     LIMIT 1'
                );
                $stmt->execute(['identifier' => strtolower($identifier)]);
            } else {
                // Normalize phone input (strip spaces and hyphens)
                $cleanPhone = preg_replace('/[^0-9+]/', '', $identifier);
                // Also check without +91 or with +91
                $digitsOnly = preg_replace('/[^0-9]/', '', $identifier);
                $last10 = strlen($digitsOnly) >= 10 ? substr($digitsOnly, -10) : $digitsOnly;

                $stmt = $pdo->prepare(
                    'SELECT id, role, full_name, email, phone, gender, avatar_url, password_hash, is_active
                     FROM users
                     WHERE phone = :p1
                        OR phone = :p2
                        OR REPLACE(REPLACE(REPLACE(phone, " ", ""), "-", ""), "+", "") LIKE :p3
                        OR email = :identifier
                     LIMIT 1'
                );
                $stmt->execute([
                    'p1' => $identifier,
                    'p2' => $cleanPhone,
                    'p3' => '%' . $last10,
                    'identifier' => $identifier,
                ]);
            }
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password_hash'])) {
                Response::error('Invalid email or password.', 401);
            }

            // Only allow customers through customer login
            if ($user['role'] !== 'customer') {
                Response::error('This account is registered for ' . ucfirst($user['role']) . '. Please use the TechKart Buddy app.', 403);
            }

            if ((int)$user['is_active'] !== 1) {
                Response::error('Your account is inactive. Please contact support.', 403);
            }

            $userId = (int)$user['id'];
            $token = Jwt::generate([
                'sub'   => $userId,
                'email' => $user['email'],
                'role'  => 'customer',
            ]);
            $refreshToken = Jwt::createRefreshToken($userId, $request->getIp());

            // Never expose password hash
            unset($user['password_hash']);

            Response::json([
                'user'          => $user,
                'token'         => $token,
                'refresh_token' => $refreshToken,
                'token_type'    => 'Bearer',
            ], 200, 'Login successful');

        } catch (Throwable $e) {
            Response::error('Login failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Customer Refresh Token Rotation
     * POST /api/v1/customer/auth/refresh-token
     * Body: { refresh_token }
     */
    public function refreshToken(Request $request): void
    {
        $refreshToken = trim((string)$request->get('refresh_token', ''));
        if (empty($refreshToken)) {
            Response::error('Refresh token is required.', 422);
        }

        $rotated = Jwt::rotateRefreshToken($refreshToken, $request->getIp());
        if (!$rotated) {
            Response::error('Invalid, expired or revoked refresh token. Please sign in again.', 401);
        }

        Response::json([
            'token'         => $rotated['access_token'],
            'refresh_token' => $rotated['refresh_token'],
            'token_type'    => 'Bearer',
            'user'          => $rotated['user'],
        ], 200, 'Session refreshed successfully');
    }

    /**
     * Authenticated Customer Profile
     * GET /api/v1/customer/auth/profile
     */
    public function profile(Request $request): void
    {
        $user = $request->getUser();

        if (!$user) {
            Response::error('Unauthorized', 401);
        }

        $userId = (int)$user['id'];
        $stats = [
            'order_count'         => 0,
            'orders_in_progress'  => 0,
            'wishlist_count'      => 0,
            'active_returns'      => 0,
            'pending_reviews'     => 0,
            'address_count'       => 0,
            'wallet_balance'      => 0.00,
            'vip_tier'            => 'MEMBER',
            'default_address'     => null,
            'payment_summary'     => null,
            'active_delivery'     => null,
        ];

        try {
            $pdo = Database::getConnection();

            // Order count and in-progress count
            $orderCountStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE customer_id = :uid');
            $orderCountStmt->execute(['uid' => $userId]);
            $stats['order_count'] = (int)$orderCountStmt->fetchColumn();

            $inProgStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = :uid AND order_status IN ('placed', 'processing', 'shipped')");
            $inProgStmt->execute(['uid' => $userId]);
            $stats['orders_in_progress'] = (int)$inProgStmt->fetchColumn();

            // Wishlist count
            $wishStmt = $pdo->prepare('SELECT COUNT(*) FROM wishlist_items WHERE customer_id = :uid');
            $wishStmt->execute(['uid' => $userId]);
            $stats['wishlist_count'] = (int)$wishStmt->fetchColumn();

            // Active return requests
            $retStmt = $pdo->prepare("SELECT COUNT(*) FROM return_requests WHERE customer_id = :uid AND status IN ('pending', 'approved', 'pickup_scheduled')");
            $retStmt->execute(['uid' => $userId]);
            $stats['active_returns'] = (int)$retStmt->fetchColumn();

            // Pending product reviews: delivered items not yet reviewed
            $revStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM order_items oi
                 JOIN orders o ON oi.order_id = o.id
                 WHERE o.customer_id = :uid AND o.order_status = 'delivered'
                 AND NOT EXISTS (SELECT 1 FROM product_reviews pr WHERE pr.customer_id = :uid2 AND pr.product_id = oi.product_id)"
            );
            $revStmt->execute(['uid' => $userId, 'uid2' => $userId]);
            $stats['pending_reviews'] = (int)$revStmt->fetchColumn();

            // Addresses count and default address
            $addrCountStmt = $pdo->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id = :uid');
            $addrCountStmt->execute(['uid' => $userId]);
            $stats['address_count'] = (int)$addrCountStmt->fetchColumn();

            $defAddrStmt = $pdo->prepare(
                'SELECT id, label, recipient_name, phone, address_line1, address_line2, city, state, postal_code, country, is_default
                 FROM user_addresses
                 WHERE user_id = :uid
                 ORDER BY is_default DESC, id DESC
                 LIMIT 1'
            );
            $defAddrStmt->execute(['uid' => $userId]);
            $defAddr = $defAddrStmt->fetch(PDO::FETCH_ASSOC);
            if ($defAddr) {
                $stats['default_address'] = [
                    'id'            => (int)$defAddr['id'],
                    'label'         => $defAddr['label'],
                    'recipient'     => $defAddr['recipient_name'],
                    'formatted'     => $defAddr['address_line1'] . ($defAddr['city'] ? ', ' . $defAddr['city'] : '') . ($defAddr['is_default'] ? ' (Default)' : ''),
                    'line1'         => $defAddr['address_line1'],
                    'city'          => $defAddr['city'],
                    'is_default'    => (bool)(int)$defAddr['is_default'],
                ];
            }

            // Real payment methods used on user's actual orders/payments
            $payStmt = $pdo->prepare('SELECT DISTINCT payment_method FROM payments WHERE customer_id = :uid AND payment_status IN (\'paid\', \'pending\')');
            $payStmt->execute(['uid' => $userId]);
            $methods = $payStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($methods)) {
                $labels = array_map(function ($m) {
                    return match(strtolower((string)$m)) {
                        'card' => 'Credit / Debit Card',
                        'upi' => 'UPI',
                        'netbanking' => 'Net Banking',
                        'cod' => 'Cash on Delivery',
                        'bkash' => 'bKash',
                        'sslcommerz' => 'Card Gateway',
                        default => ucfirst((string)$m),
                    };
                }, $methods);
                $stats['payment_summary'] = implode(', ', $labels);
            }

            // Active shipment / out-for-delivery order
            $activeOrderStmt = $pdo->prepare(
                "SELECT o.id, o.order_number, o.order_status, o.tracking_number, o.courier_name, o.estimated_delivery, o.estimated_time, o.current_location, o.next_stop, o.shipping_address,
                        (SELECT p.title FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = o.id LIMIT 1) AS item_title
                 FROM orders o
                 WHERE o.customer_id = :uid AND o.order_status IN ('placed', 'processing', 'shipped')
                 ORDER BY o.id DESC
                 LIMIT 1"
            );
            $activeOrderStmt->execute(['uid' => $userId]);
            $activeOrder = $activeOrderStmt->fetch(PDO::FETCH_ASSOC);
            if ($activeOrder) {
                $titlePrefix = $activeOrder['item_title'] ?: ('Order #' . $activeOrder['order_number']);
                $statusLabel = match($activeOrder['order_status']) {
                    'shipped' => 'Out for delivery',
                    'processing' => 'Processing dispatch',
                    default => 'Order Placed',
                };
                $sub = '';
                if ($activeOrder['estimated_time']) {
                    $sub .= $activeOrder['estimated_time'];
                } elseif ($activeOrder['estimated_delivery']) {
                    $sub .= 'Expected ' . $activeOrder['estimated_delivery'];
                }
                $loc = $activeOrder['next_stop'] ?: $activeOrder['current_location'];
                if ($loc) {
                    $sub = $sub ? "$sub • $loc" : $loc;
                }

                $stats['active_delivery'] = [
                    'order_id'        => (int)$activeOrder['id'],
                    'order_number'    => $activeOrder['order_number'],
                    'tracking_number' => $activeOrder['tracking_number'] ?: $activeOrder['order_number'],
                    'status'          => $activeOrder['order_status'],
                    'title'           => "$titlePrefix • $statusLabel",
                    'subtitle'        => $sub ?: 'Tracking update in progress',
                ];
            }

            // VIP Tier based on real order volume
            if ($stats['order_count'] >= 10) {
                $stats['vip_tier'] = 'PLUS VIP';
            } elseif ($stats['order_count'] >= 3) {
                $stats['vip_tier'] = 'VIP';
            } else {
                $stats['vip_tier'] = 'MEMBER';
            }

        } catch (Throwable) {
            // Keep clean defaults on database query issue
        }

        // Return sanitized user profile + live persisted stats
        Response::json([
            'user'  => $user,
            'stats' => $stats,
        ], 200, 'Profile retrieved successfully');
    }

    /**
     * Update Authenticated Customer Profile
     * PUT/PATCH /api/v1/customer/auth/profile
     * Body: { full_name?, phone?, avatar_url? }
     */
    public function updateProfile(Request $request): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized', 401);
        }

        $userId = (int)$user['id'];
        $fullName = $request->get('full_name') !== null ? trim((string)$request->get('full_name')) : null;
        $phone = $request->get('phone') !== null ? trim((string)$request->get('phone')) : null;
        $avatarUrl = $request->get('avatar_url') !== null ? trim((string)$request->get('avatar_url')) : null;
        $genderRaw = $request->get('gender') !== null ? strtolower(trim((string)$request->get('gender'))) : null;
        $gender = ($genderRaw !== null && in_array($genderRaw, ['male', 'female', 'other'], true)) ? $genderRaw : null;

        $errors = [];
        if ($fullName !== null && mb_strlen($fullName) < 2) {
            $errors['full_name'] = 'Full name must be at least 2 characters.';
        }
        if ($phone !== null && $phone !== '' && !preg_match('/^[0-9+\-\s()]{7,25}$/', $phone)) {
            $errors['phone'] = 'Invalid phone number format.';
        }

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $pdo = Database::getConnection();

            if ($phone !== null && $phone !== '') {
                $checkPhone = $pdo->prepare('SELECT id FROM users WHERE phone = :phone AND id != :id LIMIT 1');
                $checkPhone->execute(['phone' => $phone, 'id' => $userId]);
                if ($checkPhone->fetch()) {
                    Response::error('Phone number is already associated with another account.', 409, ['phone' => 'Phone already in use']);
                }
            }

            $fields = [];
            $params = ['id' => $userId];

            if ($fullName !== null) {
                $fields[] = 'full_name = :full_name';
                $params['full_name'] = $fullName;
            }
            if ($phone !== null) {
                $fields[] = 'phone = :phone';
                $params['phone'] = $phone !== '' ? $phone : null;
            }
            if ($avatarUrl !== null) {
                $fields[] = 'avatar_url = :avatar_url';
                $params['avatar_url'] = $avatarUrl !== '' ? $avatarUrl : null;
            }
            if ($genderRaw !== null) {
                $fields[] = 'gender = :gender';
                $params['gender'] = $gender;
            }

            if (!empty($fields)) {
                $sql = 'UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            // Fetch refreshed user record
            $refreshedStmt = $pdo->prepare('SELECT id, role, full_name, email, phone, gender, avatar_url, is_active, created_at, updated_at FROM users WHERE id = :id LIMIT 1');
            $refreshedStmt->execute(['id' => $userId]);
            $updatedUser = $refreshedStmt->fetch(PDO::FETCH_ASSOC);

            Response::json([
                'user' => $updatedUser,
            ], 200, 'Customer profile updated successfully');
        } catch (Throwable $e) {
            Response::error('Profile update failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Request a password reset code
     * POST /api/v1/customer/auth/forgot-password
     * Body: { email }
     */
    public function forgotPassword(Request $request): void
    {
        $email = strtolower(trim((string)$request->get('email', '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('A valid email address is required.', 422, [
                'email' => 'Enter a valid email address.',
            ]);
        }

        if (!MailService::isConfigured()) {
            Response::error('Password reset email delivery is not configured.', 503);
        }

        $genericMessage = 'If an account exists for that email, a verification code has been sent.';

        try {
            $pdo = Database::getConnection();

            $userStmt = $pdo->prepare(
                'SELECT id, full_name, email, role, is_active
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );
            $userStmt->execute(['email' => $email]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || $user['role'] !== 'customer' || (int)$user['is_active'] !== 1) {
                Response::json(['expires_in' => 600, 'resend_after' => 60], 200, $genericMessage);
            }

            $recentStmt = $pdo->prepare(
                'SELECT created_at
                 FROM password_reset_otps
                 WHERE email = :email
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $recentStmt->execute(['email' => $email]);
            $recent = $recentStmt->fetch(PDO::FETCH_ASSOC);

            if ($recent && !empty($recent['created_at'])) {
                $elapsed = time() - (int)strtotime((string)$recent['created_at']);
                if ($elapsed >= 0 && $elapsed < 60) {
                    Response::error('Please wait before requesting another code.', 429, [
                        'retry_after' => 60 - $elapsed,
                    ]);
                }
            }

            $hourlyStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM password_reset_otps
                 WHERE email = :email AND created_at >= :since'
            );
            $hourlyStmt->execute([
                'email' => $email,
                'since' => date('Y-m-d H:i:s', time() - 3600),
            ]);

            if ((int)$hourlyStmt->fetchColumn() >= 5) {
                Response::error('Too many reset code requests. Please try again later.', 429);
            }

            $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', time() + 600);

            $insertStmt = $pdo->prepare(
                'INSERT INTO password_reset_otps
                    (user_id, email, otp_hash, expires_at, request_ip)
                 VALUES
                    (:user_id, :email, :otp_hash, :expires_at, :request_ip)'
            );
            $insertStmt->execute([
                'user_id' => (int)$user['id'],
                'email' => $email,
                'otp_hash' => hash('sha256', $otp),
                'expires_at' => $expiresAt,
                'request_ip' => substr($request->getIp(), 0, 45),
            ]);

            $resetId = (int)$pdo->lastInsertId();

            try {
                MailService::sendPasswordResetCode(
                    $email,
                    (string)$user['full_name'],
                    $otp,
                    10
                );
            } catch (Throwable $mailError) {
                $pdo->prepare('DELETE FROM password_reset_otps WHERE id = :id')
                    ->execute(['id' => $resetId]);
                error_log('[CustomerAuthController] Password reset email failed: ' . $mailError->getMessage());
                Response::error('Unable to send the reset email right now. Please try again later.', 502);
            }

            Response::json(['expires_in' => 600, 'resend_after' => 60], 200, $genericMessage);

        } catch (Throwable $e) {
            Response::error('Unable to process the password reset request.', 500);
        }
    }

    /**
     * Verify a password reset code
     * POST /api/v1/customer/auth/verify-reset-otp
     * Body: { email, otp }
     */
    public function verifyResetOtp(Request $request): void
    {
        $email = strtolower(trim((string)$request->get('email', '')));
        $otp = trim((string)$request->get('otp', ''));

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (!preg_match('/^\d{6}$/', $otp)) {
            $errors['otp'] = 'Enter the 6-digit verification code.';
        }
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare(
                'SELECT id, user_id, otp_hash, expires_at, attempts
                 FROM password_reset_otps
                 WHERE email = :email
                   AND consumed_at IS NULL
                   AND verified_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $reset = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reset) {
                Response::error('The verification code is invalid or has expired.', 400);
            }

            if ((int)$reset['attempts'] >= 5) {
                $pdo->prepare('UPDATE password_reset_otps SET consumed_at = :now WHERE id = :id')
                    ->execute(['now' => date('Y-m-d H:i:s'), 'id' => (int)$reset['id']]);
                Response::error('Too many incorrect attempts. Request a new code.', 429);
            }

            if (strtotime((string)$reset['expires_at']) < time()) {
                $pdo->prepare('UPDATE password_reset_otps SET consumed_at = :now WHERE id = :id')
                    ->execute(['now' => date('Y-m-d H:i:s'), 'id' => (int)$reset['id']]);
                Response::error('The verification code is invalid or has expired.', 400);
            }

            if (!hash_equals((string)$reset['otp_hash'], hash('sha256', $otp))) {
                $nextAttempts = (int)$reset['attempts'] + 1;
                $pdo->prepare(
                    'UPDATE password_reset_otps
                     SET attempts = :attempts, consumed_at = :consumed_at
                     WHERE id = :id'
                )->execute([
                    'attempts' => $nextAttempts,
                    'consumed_at' => $nextAttempts >= 5 ? date('Y-m-d H:i:s') : null,
                    'id' => (int)$reset['id'],
                ]);

                Response::error('The verification code is invalid or has expired.', 400);
            }

            $resetToken = bin2hex(random_bytes(32));
            $tokenExpiresAt = date('Y-m-d H:i:s', time() + 900);

            $pdo->beginTransaction();

            $pdo->prepare(
                'UPDATE password_reset_otps
                 SET verified_at = :verified_at,
                     reset_token_hash = :reset_token_hash,
                     reset_token_expires_at = :reset_token_expires_at
                 WHERE id = :id'
            )->execute([
                'verified_at' => date('Y-m-d H:i:s'),
                'reset_token_hash' => hash('sha256', $resetToken),
                'reset_token_expires_at' => $tokenExpiresAt,
                'id' => (int)$reset['id'],
            ]);

            $pdo->prepare(
                'UPDATE password_reset_otps
                 SET consumed_at = :now
                 WHERE user_id = :user_id
                   AND id != :id
                   AND consumed_at IS NULL'
            )->execute([
                'now' => date('Y-m-d H:i:s'),
                'user_id' => (int)$reset['user_id'],
                'id' => (int)$reset['id'],
            ]);

            $pdo->commit();

            Response::json([
                'email' => $email,
                'reset_token' => $resetToken,
                'expires_in' => 900,
            ], 200, 'Verification code accepted');

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Unable to verify the reset code right now.', 500);
        }
    }

    /**
     * Reset the customer password using a verified reset token
     * POST /api/v1/customer/auth/reset-password
     * Body: { email, reset_token, new_password }
     */
    public function resetPassword(Request $request): void
    {
        $email = strtolower(trim((string)$request->get('email', '')));
        $resetToken = trim((string)$request->get('reset_token', ''));
        $newPassword = (string)$request->get('new_password', '');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $resetToken)) {
            $errors['reset_token'] = 'The reset session is invalid or has expired.';
        }
        if (strlen($newPassword) < 8) {
            $errors['new_password'] = 'Password must be at least 8 characters.';
        }
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare(
                'SELECT pr.id, pr.user_id, u.role, u.is_active
                 FROM password_reset_otps pr
                 JOIN users u ON u.id = pr.user_id
                 WHERE pr.email = :email
                   AND pr.reset_token_hash = :reset_token_hash
                   AND pr.verified_at IS NOT NULL
                   AND pr.consumed_at IS NULL
                   AND pr.reset_token_expires_at IS NOT NULL
                   AND pr.reset_token_expires_at >= :now
                 ORDER BY pr.id DESC
                 LIMIT 1'
            );
            $stmt->execute([
                'email' => $email,
                'reset_token_hash' => hash('sha256', $resetToken),
                'now' => date('Y-m-d H:i:s'),
            ]);
            $reset = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reset || $reset['role'] !== 'customer' || (int)$reset['is_active'] !== 1) {
                Response::error('The reset session is invalid or has expired.', 400);
            }

            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $now = date('Y-m-d H:i:s');

            $pdo->beginTransaction();

            $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash,
                     password_changed_at = :password_changed_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            )->execute([
                'password_hash' => $passwordHash,
                'password_changed_at' => $now,
                'updated_at' => $now,
                'id' => (int)$reset['user_id'],
            ]);

            $pdo->prepare(
                'UPDATE password_reset_otps
                 SET consumed_at = :consumed_at
                 WHERE id = :id'
            )->execute([
                'consumed_at' => $now,
                'id' => (int)$reset['id'],
            ]);

            // Revoke all active refresh tokens for this user on password reset
            Jwt::revokeAllUserRefreshTokens((int)$reset['user_id']);

            $pdo->commit();

            Response::json([
                'message' => 'Password reset successfully. Please sign in with your new password.',
            ], 200, 'Password reset successfully');

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Unable to reset the password right now.', 500);
        }
    }

    /**
     * Customer Logout & Session Token Revocation
     * POST /api/v1/customer/auth/logout
     */
    public function logout(Request $request): void
    {
        $user = $request->getUser();
        $token = $request->getBearerToken();
        $refreshToken = trim((string)$request->get('refresh_token', ''));

        if ($token) {
            Jwt::blacklist($token, $user ? (int)$user['id'] : null);
        }
        if (!empty($refreshToken)) {
            Jwt::revokeRefreshToken($refreshToken);
        } elseif ($user) {
            Jwt::revokeAllUserRefreshTokens((int)$user['id']);
        }

        if ($user) {
            try {
                $pdo = Database::getConnection();
                $pdo->prepare(
                    'INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, details)
                     VALUES (:aid, :role, :name, :action, :target, :tid, :det)'
                )->execute([
                    'aid'    => (int)$user['id'],
                    'role'   => $user['role'],
                    'name'   => $user['full_name'] ?? 'Customer User',
                    'action' => 'USER_LOGOUT',
                    'target' => 'Session',
                    'tid'    => (string)$user['id'],
                    'det'    => json_encode(['email' => $user['email'], 'logged_out_at' => date('Y-m-d H:i:s')]),
                ]);
            } catch (Throwable $e) {
                // Non-blocking audit log
            }
        }

        Response::json([
            'logged_out' => true,
        ], 200, 'Successfully logged out and customer session invalidated');
    }
}
