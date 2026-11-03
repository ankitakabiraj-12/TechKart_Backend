<?php

declare(strict_types=1);

// Application Root Directory
define('ROOT_DIR', dirname(__DIR__));

// Set Default Timezone to Indian Standard Time (IST)
date_default_timezone_set('Asia/Kolkata');

// PSR-4 Autoloader for 'App\' namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = ROOT_DIR . '/app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Load Environment Configuration
\App\Core\Env::load(ROOT_DIR . '/.env');

// Error Handling Configuration based on Environment
$debug = (bool)\App\Core\Env::get('APP_DEBUG', true);
if ($debug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
}

// Global Exception Handler for JSON APIs
set_exception_handler(function (Throwable $exception): void {
    error_log((string)$exception);

    $debug = (bool)\App\Core\Env::get('APP_DEBUG', false);

    $statusCode = ($exception->getCode() >= 400 && $exception->getCode() < 600)
        ? (int)$exception->getCode()
        : 500;

    $message = $debug || $statusCode < 500
        ? $exception->getMessage()
        : 'Internal Server Error';

    $errorDetails = $debug ? [
        'type' => get_class($exception),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
    ] : null;

    \App\Core\Response::error(
        $message,
        $statusCode,
        $errorDetails
    );
});
