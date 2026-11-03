<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Env;
use App\Core\Request;

/**
 * Handles Cross-Origin Resource Sharing (CORS) headers and preflight OPTIONS requests.
 */
class CorsMiddleware
{
    public function handle(Request $request): void
    {
        $allowedOrigins = Env::get('CORS_ALLOWED_ORIGINS', '*');
        $origin = $request->getHeader('Origin', '*');

        if ($allowedOrigins === '*') {
            header('Access-Control-Allow-Origin: *');
        } else {
            $origins = array_map('trim', explode(',', (string)$allowedOrigins));
            if (in_array($origin, $origins, true)) {
                header("Access-Control-Allow-Origin: {$origin}");
            }
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-App-Client');
        header('Access-Control-Max-Age: 86400');

        if ($request->getMethod() === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
}
