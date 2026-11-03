<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight pure-PHP JWT Implementation (HMAC-SHA256)
 * No third-party dependencies required.
 */
class Jwt
{
    /**
     * Generate a signed JWT string.
     *
     * @throws \RuntimeException if JWT_SECRET is missing or empty.
     */
    public static function generate(array $payload, ?int $expirySeconds = null): string
    {
        $secret = trim((string)Env::get('JWT_SECRET', ''));
        if ($secret === '') {
            throw new \RuntimeException('JWT secret is not configured or is empty.');
        }

        $ttl = $expirySeconds ?? (int)Env::get('JWT_EXPIRY', 604800);

        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttl;

        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256'
        ];

        $base64UrlHeader = self::base64UrlEncode(json_encode($header));
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', "{$base64UrlHeader}.{$base64UrlPayload}", $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return "{$base64UrlHeader}.{$base64UrlPayload}.{$base64UrlSignature}";
    }

    /**
     * Validate and decode a JWT string.
     * Returns the payload array if valid, or null if expired/invalid signature/missing secret.
     */
    public static function verify(string $jwt): ?array
    {
        $secret = trim((string)Env::get('JWT_SECRET', ''));
        if ($secret === '') {
            return null;
        }

        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        [$base64UrlHeader, $base64UrlPayload, $base64UrlSignature] = $parts;

        // Verify HMAC-SHA256 signature
        $expectedSignature = hash_hmac('sha256', "{$base64UrlHeader}.{$base64UrlPayload}", $secret, true);
        $expectedBase64UrlSignature = self::base64UrlEncode($expectedSignature);

        if (!hash_equals($expectedBase64UrlSignature, $base64UrlSignature)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($base64UrlPayload);
        if (!$payloadJson) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && time() >= (int)$payload['exp']) {
            return null; // Expired
        }

        // Check if token has been invalidated/blacklisted
        if (self::isBlacklisted($jwt)) {
            return null; // Invalidated / Logged out
        }

        return $payload;
    }

    /**
     * Check if a JWT token has been blacklisted in MariaDB
     */
    public static function isBlacklisted(string $jwt): bool
    {
        try {
            $pdo = Database::getConnection();
            $hash = hash('sha256', $jwt);
            $stmt = $pdo->prepare('SELECT id FROM token_blacklist WHERE token_hash = :hash LIMIT 1');
            $stmt->execute(['hash' => $hash]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Issue a secure, cryptographically random refresh token and persist in MySQL.
     * Default TTL: 30 days (2,592,000 seconds).
     */
    public static function createRefreshToken(int $userId, ?string $requestIp = null, int $ttlSeconds = 2592000): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, request_ip)
             VALUES (:uid, :hash, :expires_at, :ip)'
        );
        $stmt->execute([
            'uid'        => $userId,
            'hash'       => $hash,
            'expires_at' => $expiresAt,
            'ip'         => $requestIp ? substr($requestIp, 0, 45) : null,
        ]);

        return $rawToken;
    }

    /**
     * Rotate a refresh token: verifies current token, revokes it, and issues a new pair.
     * Enforces replay detection: if an already-revoked token is used, revokes all user sessions.
     */
    public static function rotateRefreshToken(string $rawToken, ?string $requestIp = null): ?array
    {
        try {
            $pdo = Database::getConnection();
            $hash = hash('sha256', $rawToken);

            $stmt = $pdo->prepare('SELECT * FROM refresh_tokens WHERE token_hash = :hash LIMIT 1');
            $stmt->execute(['hash' => $hash]);
            $record = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$record) {
                return null;
            }

            $userId = (int)$record['user_id'];

            // Replay detection: if token is already revoked, potential token theft; invalidate all refresh tokens for this user!
            if (!empty($record['revoked_at'])) {
                $pdo->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = :uid AND revoked_at IS NULL')
                    ->execute(['uid' => $userId]);
                return null;
            }

            // Expiry check
            if (strtotime((string)$record['expires_at']) <= time()) {
                $pdo->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = :id')
                    ->execute(['id' => (int)$record['id']]);
                return null;
            }

            // Verify user exists and is active
            $userStmt = $pdo->prepare('SELECT id, role, full_name, email, phone, gender, avatar_url, is_active FROM users WHERE id = :uid LIMIT 1');
            $userStmt->execute(['uid' => $userId]);
            $user = $userStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user || (int)$user['is_active'] !== 1) {
                return null;
            }

            // Generate new refresh token
            $newRawToken = bin2hex(random_bytes(32));
            $newHash = hash('sha256', $newRawToken);
            $newExpiresAt = date('Y-m-d H:i:s', time() + 2592000); // 30 days

            $pdo->beginTransaction();

            // Mark old token revoked and record replacement
            $updateStmt = $pdo->prepare(
                'UPDATE refresh_tokens SET revoked_at = NOW(), replaced_by_hash = :new_hash WHERE id = :id'
            );
            $updateStmt->execute(['new_hash' => $newHash, 'id' => (int)$record['id']]);

            // Insert new refresh token
            $insertStmt = $pdo->prepare(
                'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, request_ip)
                 VALUES (:uid, :hash, :expires_at, :ip)'
            );
            $insertStmt->execute([
                'uid'        => $userId,
                'hash'       => $newHash,
                'expires_at' => $newExpiresAt,
                'ip'         => $requestIp ? substr($requestIp, 0, 45) : null,
            ]);

            $pdo->commit();

            // Generate new access JWT token
            $newAccessToken = self::generate([
                'sub'   => $userId,
                'email' => $user['email'],
                'role'  => $user['role'],
            ]);

            return [
                'access_token'  => $newAccessToken,
                'refresh_token' => $newRawToken,
                'user'          => $user,
            ];

        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }
    }

    /**
     * Revoke a refresh token (e.g. on logout)
     */
    public static function revokeRefreshToken(string $rawToken): bool
    {
        try {
            $pdo = Database::getConnection();
            $hash = hash('sha256', $rawToken);
            $stmt = $pdo->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = :hash AND revoked_at IS NULL');
            return $stmt->execute(['hash' => $hash]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Revoke all refresh tokens for a user (e.g. after password reset)
     */
    public static function revokeAllUserRefreshTokens(int $userId): bool
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = :uid AND revoked_at IS NULL');
            return $stmt->execute(['uid' => $userId]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Invalidate/blacklist a token in MariaDB (e.g. upon logout)
     */
    public static function blacklist(string $jwt, ?int $userId = null): bool
    {
        try {
            $payload = null;
            $parts = explode('.', $jwt);
            if (count($parts) === 3) {
                $payloadJson = self::base64UrlDecode($parts[1]);
                if ($payloadJson) {
                    $payload = json_decode($payloadJson, true);
                }
            }

            $expTimestamp = isset($payload['exp']) ? (int)$payload['exp'] : time() + 604800;
            $expiresAt = date('Y-m-d H:i:s', $expTimestamp);
            $hash = hash('sha256', $jwt);
            $uid = $userId ?? ($payload['sub'] ?? null);

            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO token_blacklist (token_hash, user_id, expires_at)
                 VALUES (:hash, :uid, :expires_at)'
            );
            return $stmt->execute([
                'hash'       => $hash,
                'uid'        => $uid ? (int)$uid : null,
                'expires_at' => $expiresAt,
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode(string $data): string|false
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padLen = 4 - $remainder;
            $data .= str_repeat('=', $padLen);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}
