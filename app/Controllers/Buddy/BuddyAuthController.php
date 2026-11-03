<?php

declare(strict_types=1);

namespace App\Controllers\Buddy;

use App\Core\Database;
use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling TechKart Buddy (Seller / Admin) Authentication
 */
class BuddyAuthController
{
    /**
     * Seller Self-Registration (TechKart Buddy)
     * POST /api/v1/buddy/auth/register-seller
     * Body: { full_name, email, password, store_name, phone?, gst_number?, trade_license?, tin_number?, category?, city?, state?, pincode?, address? }
     */
    public function registerSeller(Request $request): void
    {
        $fullName     = trim((string)$request->get('full_name', ''));
        $email        = strtolower(trim((string)$request->get('email', '')));
        $password     = (string)$request->get('password', '');
        $storeName    = trim((string)$request->get('store_name', ''));
        $phone        = trim((string)$request->get('phone', '')) ?: null;
        $tradeLicense = trim((string)$request->get('trade_license', '')) ?: null;
        $tinNumber    = trim((string)$request->get('tin_number', '')) ?: null;
        $gstNumber    = trim((string)$request->get('gst_number', '')) ?: ($tinNumber ?: null);
        $category     = trim((string)$request->get('category', '')) ?: 'Consumer Electronics & Gadgets';
        $city         = trim((string)$request->get('city', '')) ?: 'Mumbai';
        $state        = trim((string)$request->get('state', '')) ?: 'Maharashtra';
        $pincode      = trim((string)$request->get('pincode', '')) ?: '400001';
        $address      = trim((string)$request->get('address', '')) ?: null;

        // Validation
        $errors = [];
        if (mb_strlen($fullName) < 2) {
            $errors['full_name'] = 'Full name is required (minimum 2 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid business email address is required.';
        }
        if (strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }
        if (mb_strlen($storeName) < 2) {
            $errors['store_name'] = 'Store name is required (minimum 2 characters).';
        }

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        try {
            $pdo = Database::getConnection();

            // Check if email already exists in users
            $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $checkEmail->execute(['email' => $email]);
            if ($checkEmail->fetch()) {
                Response::error('Email is already registered.', 409, ['email' => 'Email already registered']);
            }

            // Check if store name is already registered
            $checkStore = $pdo->prepare('SELECT id FROM seller_profiles WHERE LOWER(store_name) = LOWER(:store_name) LIMIT 1');
            $checkStore->execute(['store_name' => $storeName]);
            if ($checkStore->fetch()) {
                Response::error('Store name is already taken. Please choose another name.', 409, ['store_name' => 'Store name already registered']);
            }

            // Begin transaction for atomic creation
            $pdo->beginTransaction();

            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            // 1. Create seller user (active account with seller role)
            $stmtUser = $pdo->prepare(
                'INSERT INTO users (role, full_name, email, phone, password_hash, is_active)
                 VALUES (:role, :full_name, :email, :phone, :password_hash, 1)'
            );
            $stmtUser->execute([
                'role'          => 'seller',
                'full_name'     => $fullName,
                'email'         => $email,
                'phone'         => $phone,
                'password_hash' => $passwordHash,
            ]);

            $userId = (int)$pdo->lastInsertId();

            // 2. Create seller store profile (is_verified = 0 pending admin KYC approval)
            $stmtProfile = $pdo->prepare(
                'INSERT INTO seller_profiles (user_id, store_name, gst_number, address, city, state, pincode, is_verified)
                 VALUES (:user_id, :store_name, :gst_number, :address, :city, :state, :pincode, 0)'
            );
            $stmtProfile->execute([
                'user_id'    => $userId,
                'store_name' => $storeName,
                'gst_number' => $gstNumber,
                'address'    => $address,
                'city'       => $city,
                'state'      => $state,
                'pincode'    => $pincode,
            ]);

            // 3. Register application in seller_applications queue for Admin KYC audit
            $appNumber = 'APP-' . strtoupper(substr(uniqid(), -6));
            $slaDeadline = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $stmtApp = $pdo->prepare(
                'INSERT INTO seller_applications (app_number, business_name, applicant_name, email, phone, category, trade_license, tin_number, status, sla_deadline, notes)
                 VALUES (:app_number, :business_name, :applicant_name, :email, :phone, :category, :trade_license, :tin_number, :status, :sla, :notes)'
            );
            $stmtApp->execute([
                'app_number'     => $appNumber,
                'business_name'  => $storeName,
                'applicant_name' => $fullName,
                'email'          => $email,
                'phone'          => $phone ?? 'N/A',
                'category'       => $category,
                'trade_license'  => $tradeLicense ?? 'TRAD/ONLINE/' . rand(100000, 999999),
                'tin_number'     => $tinNumber ?? ($gstNumber ?? 'TIN-' . rand(1000000000, 9999999999)),
                'status'         => 'pending',
                'sla'            => $slaDeadline,
                'notes'          => 'Registered via TechKart Buddy Seller Onboarding portal. Pending initial compliance verification.',
            ]);

            // 4. Add initial audit log entry
            $pdo->prepare(
                'INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, details)
                 VALUES (:aid, :role, :name, :action, :target, :tid, :det)'
            )->execute([
                'aid'    => $userId,
                'role'   => 'seller',
                'name'   => $fullName,
                'action' => 'SELLER_REGISTRATION_SUBMITTED',
                'target' => 'SellerApplication',
                'tid'    => $appNumber,
                'det'    => json_encode([
                    'store_name'    => $storeName,
                    'email'         => $email,
                    'app_number'    => $appNumber,
                    'status'        => 'pending',
                    'category'      => $category,
                ]),
            ]);

            $pdo->commit();

            // Generate JWT Token for immediate authenticated session
            $token = Jwt::generate([
                'sub'   => $userId,
                'email' => $email,
                'role'  => 'seller',
            ]);

            Response::json([
                'user' => [
                    'id'         => $userId,
                    'role'       => 'seller',
                    'full_name'  => $fullName,
                    'email'      => $email,
                    'phone'      => $phone,
                ],
                'store' => [
                    'store_name'  => $storeName,
                    'gst_number'  => $gstNumber,
                    'city'        => $city,
                    'state'       => $state,
                    'is_verified' => 0,
                ],
                'application' => [
                    'app_number'   => $appNumber,
                    'status'       => 'pending',
                    'sla_deadline' => $slaDeadline,
                ],
                'token'      => $token,
                'token_type' => 'Bearer',
            ], 201, 'Seller account and store registered successfully');

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Seller registration failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Seller & Admin Login (TechKart Buddy)
     * POST /api/v1/buddy/auth/login
     * Body: { email, password }
     */
    public function login(Request $request): void
    {
        $email    = strtolower(trim((string)$request->get('email', '')));
        $password = (string)$request->get('password', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($password)) {
            Response::error('Invalid email or password provided.', 422);
        }

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare(
                'SELECT id, role, full_name, email, phone, avatar_url, password_hash, is_active
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password_hash'])) {
                Response::error('Invalid email or password.', 401);
            }

            // Only allow seller or admin roles
            if (!in_array($user['role'], ['seller', 'admin'], true)) {
                Response::error('Access restricted to sellers and administrators. Customer accounts are not permitted on TechKart Buddy.', 403);
            }

            if ((int)$user['is_active'] !== 1) {
                Response::error('Your account is deactivated. Please contact administrator.', 403);
            }

            $userId = (int)$user['id'];
            $role   = $user['role'];

            // Fetch seller profile if seller
            $storeProfile = null;
            if ($role === 'seller') {
                $profileStmt = $pdo->prepare('SELECT * FROM seller_profiles WHERE user_id = :user_id LIMIT 1');
                $profileStmt->execute(['user_id' => $userId]);
                $storeProfile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $token = Jwt::generate([
                'sub'   => $userId,
                'email' => $user['email'],
                'role'  => $role,
            ]);

            // Never expose password hash
            unset($user['password_hash']);

            Response::json([
                'user'         => $user,
                'role'         => $role,
                'email'        => $user['email'],
                'full_name'    => $user['full_name'],
                'store'        => $storeProfile,
                'store_name'   => $storeProfile['store_name'] ?? null,
                'token'        => $token,
                'token_type'   => 'Bearer',
            ], 200, ucfirst($role) . ' login successful');

        } catch (Throwable $e) {
            Response::error('Login failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Authenticated Buddy Profile (Seller / Admin)
     * GET /api/v1/buddy/auth/profile
     */
    public function profile(Request $request): void
    {
        $user = $request->getUser();

        if (!$user) {
            Response::error('Unauthorized', 401);
        }

        try {
            $pdo = Database::getConnection();
            $storeProfile = null;

            if ($user['role'] === 'seller') {
                $profileStmt = $pdo->prepare('SELECT * FROM seller_profiles WHERE user_id = :user_id LIMIT 1');
                $profileStmt->execute(['user_id' => (int)$user['id']]);
                $storeProfile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            Response::json([
                'user'  => $user,
                'store' => $storeProfile,
            ], 200, 'Profile retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve profile: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Validate Active JWT Session and Role Permissions
     * GET /api/v1/buddy/auth/validate-session
     */
    public function validateSession(Request $request): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized. Invalid or expired session.', 401);
        }

        try {
            $pdo = Database::getConnection();
            $storeProfile = null;

            if ($user['role'] === 'seller') {
                $profileStmt = $pdo->prepare('SELECT * FROM seller_profiles WHERE user_id = :user_id LIMIT 1');
                $profileStmt->execute(['user_id' => (int)$user['id']]);
                $storeProfile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            Response::json([
                'valid' => true,
                'user' => [
                    'id'        => (int)$user['id'],
                    'role'      => $user['role'],
                    'full_name' => $user['full_name'],
                    'email'     => $user['email'],
                    'phone'     => $user['phone'],
                ],
                'role'  => $user['role'],
                'store' => $storeProfile,
            ], 200, 'Session is valid and active');
        } catch (Throwable $e) {
            Response::error('Session validation error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Secure Logout / Invalidate Session Token
     * POST /api/v1/buddy/auth/logout
     */
    public function logout(Request $request): void
    {
        $user = $request->getUser();
        $token = $request->getBearerToken();

        if ($token) {
            Jwt::blacklist($token, $user ? (int)$user['id'] : null);
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
                    'name'   => $user['full_name'] ?? 'Buddy User',
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
        ], 200, 'Successfully logged out and session token invalidated');
    }
}
