<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Standard JSON HTTP Response Helper
 */
class Response
{
    public static function json(mixed $data = null, int $statusCode = 200, string $message = 'Success', array $extra = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');

        $isSuccess = $statusCode >= 200 && $statusCode < 300;

        $response = array_merge([
            'success' => $isSuccess,
            'status' => $statusCode,
            'message' => $message,
            'data' => $data,
        ], $extra);

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message = 'Internal Server Error', int $statusCode = 500, mixed $errors = null): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');

        $response = [
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'errors' => $errors,
        ];

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
