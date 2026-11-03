<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Database;
use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Authentication Middleware for validating JWT Bearer tokens and enforcing roles.
 */
class AuthMiddleware
{
    /** @var array<string> */
    private array $allowedRoles;

    /**
     * @param string|array<string>|null $allowedRoles Role or list of roles allowed ('customer', 'seller', 'admin')
     */
    public function __construct(string|array|null $allowedRoles = null)
    {
        if (is_string($allowedRoles)) {
            $this->allowedRoles = [$allowedRoles];
        } elseif (is_array($allowedRoles)) {
            $this->allowedRoles = $allowedRoles;
        } else {
            $this->allowedRoles = [];
        }
    }

    public function handle(Request $request): void
    {
        $token = $request->getBearerToken();

        if (!$token) {
            Response::error('Unauthorized. Missing Bearer authentication token.', 401);
        }

        $payload = Jwt::verify($token);
        if (!$payload || empty($payload['sub'])) {
            Response::error('Unauthorized. Invalid or expired token.', 401);
        }

        $userId = (int)$payload['sub'];

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT id, role, full_name, email, phone, gender, password_changed_at, avatar_url, is_active, created_at, updated_at
                 FROM users
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                Response::error('Unauthorized. User not found.', 401);
            }

            if ((int)$user['is_active'] !== 1) {
                Response::error('Forbidden. User account is deactivated.', 403);
            }

            $passwordChangedAt = $user['password_changed_at'] ?? null;
            $tokenIssuedAt = isset($payload['iat']) ? (int)$payload['iat'] : 0;

            if (!empty($passwordChangedAt) && $tokenIssuedAt < (int)strtotime((string)$passwordChangedAt)) {
                Response::error('Unauthorized. Session expired after a password change. Please sign in again.', 401);
            }

            unset($user['password_changed_at']);

            // Role verification if restrictions were specified
            if (!empty($this->allowedRoles) && !in_array($user['role'], $this->allowedRoles, true)) {
                Response::error("Forbidden. You do not have permission to access this resource.", 403);
            }

            // Attach authenticated user to Request object for controller access
            $request->setUser($user);

        } catch (Throwable $e) {
            Response::error('Authentication error: ' . $e->getMessage(), 500);
        }
    }
}
