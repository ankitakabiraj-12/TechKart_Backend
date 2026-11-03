<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * System and Health check endpoints
 */
class HealthController
{
    public function check(Request $request): void
    {
        $dbStatus = 'disconnected';
        $dbError = null;

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query('SELECT 1');
            if ($stmt !== false) {
                $dbStatus = 'connected';
            }
        } catch (Throwable $e) {
            $dbStatus = 'error';
            $dbError = $e->getMessage();
        }

        Response::json([
            'service' => 'TechKart Unified API',
            'version' => '1.0.0',
            'php_version' => PHP_VERSION,
            'database' => [
                'status' => $dbStatus,
                'error' => $dbError,
            ],
            'timestamp' => date('c'),
        ], 200, 'TechKart Backend API is operational');
    }
}
