<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Standard HTTP Request Wrapper for JSON APIs
 */
class Request
{
    private string $method;
    private string $uri;
    private array $queryParams;
    private array $body;
    private array $headers;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->uri = parse_url($rawUri, PHP_URL_PATH) ?? '/';

        $parsedQuery = [];
        $queryString = parse_url($rawUri, PHP_URL_QUERY);
        if (!empty($queryString)) {
            parse_str($queryString, $parsedQuery);
        }
        $this->queryParams = array_merge($parsedQuery, $_GET);
        $this->headers = [];
        if (function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                $this->headers = $all;
            }
        }
        if (empty($this->headers)) {
            foreach ($_SERVER as $key => $val) {
                if (str_starts_with($key, 'HTTP_')) {
                    $headerName = str_replace('_', '-', substr($key, 5));
                    $this->headers[$headerName] = $val;
                } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'AUTHORIZATION'], true)) {
                    $headerName = str_replace('_', '-', $key);
                    $this->headers[$headerName] = $val;
                }
            }
        }

        // Parse JSON Body or standard POST
        $rawInput = file_get_contents('php://input');
        $json = json_decode($rawInput, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            $this->body = $json;
        } else {
            $this->body = $_POST;
        }
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
        }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function getFile(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public function getFiles(): array
    {
        return $_FILES;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        $target = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $target) {
                return $value;
            }
        }
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$serverKey] ?? $default;
    }

    private ?array $user = null;

    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    public function getUser(): ?array
    {
        return $this->user;
    }

    public function getBearerToken(): ?string
    {
        $authHeader = $this->getHeader('Authorization')
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if ($authHeader && preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function getIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
