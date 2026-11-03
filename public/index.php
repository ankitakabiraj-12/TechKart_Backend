<?php

declare(strict_types=1);

// Bootstrapping the application
require_once dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Request;
use App\Core\Router;
use App\Middlewares\CorsMiddleware;

// Handle CORS for all incoming requests
$cors = new CorsMiddleware();
$request = new Request();
$cors->handle($request);

// Initialize router
$router = new Router();

// Load API routes
require_once ROOT_DIR . '/routes/api_v1.php';

// Dispatch current request
$router->dispatch($request);
