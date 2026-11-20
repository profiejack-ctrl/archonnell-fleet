<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CentralApiClient
{
    protected string $baseUrl;
    protected string $serviceEmail;
    protected string $servicePassword;
    protected int $timeout;

    // Execution status tracking for the most recent API call
    protected ?int $lastStatusCode = null;
    protected ?string $lastError = null;
    protected bool $lastRequestSuccessful = false;
    protected bool $lastRequestUnavailable = false;
    protected ?array $lastResponseData = null;

    public function __construct(?string $baseUrl = null, ?int $timeout = null)
    {
        $rawUrl = $baseUrl
            ?? config('services.central_api.base_url')
            ?? env('CENTRAL_API_URL')
            ?? env('CENTRAL_GATEWAY_URL')
            ?? 'http://localhost:4000/api/v1';

        // Strip trailing /fleet or /fleet/ to ensure baseUrl is always the root gateway /api/v1
        $rawUrl = preg_replace('#/fleet/?$#i', '', $rawUrl);

        // Normalize base URL so it includes /api/v1 if not present
        if (!str_contains($rawUrl, '/api/v1')) {
            $rawUrl = rtrim($rawUrl, '/') . '/api/v1';
        }

        $this->baseUrl = rtrim($rawUrl, '/');
        $this->serviceEmail = env('CENTRAL_SERVICE_EMAIL', 'admin@archonnell.com');
        $this->servicePassword = env('CENTRAL_SERVICE_PASSWORD', 'Admin123!');
        $this->timeout = $timeout ?? (int) env('CENTRAL_API_TIMEOUT', 4);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function wasSuccessful(): bool
    {
        return $this->lastRequestSuccessful;
    }

    public function isUnavailable(): bool
    {
        return $this->lastRequestUnavailable;
    }

    public function getLastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastResponse(): ?array
    {
        return $this->lastResponseData;
    }

    /**
     * Acquire a JWT Bearer token from the centralized backend, cached for 1 hour.
     */
    public function getServiceToken(): ?string
    {
        return Cache::remember('central_backend_jwt_token', 3600, function () {
            try {
                $response = Http::timeout($this->timeout)
                    ->acceptJson()
                    ->post("{$this->baseUrl}/auth/login", [
                        'email' => $this->serviceEmail,
                        'password' => $this->servicePassword,
                    ]);

                if ($response->successful()) {
                    return $response->json('data.token');
                }

                Log::warning('Central API authentication failed with HTTP status ' . $response->status());
                return null;
            } catch (\Throwable $e) {
                Log::warning('Central API unreachable for auth: ' . $e->getMessage());
                return null;
            }
        });
    }

    /**
     * Check if the centralized backend is reachable and healthy.
     */
    public function isAvailable(): bool
    {
        try {
            $rootHealthUrl = preg_replace('#/api/v1.*$#', '', $this->baseUrl) . '/health';
            $response = Http::timeout(2)->get($rootHealthUrl);
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Execute a GET request against the Centralized Backend.
     */
    public function get(string $endpoint, array $query = []): ?array
    {
        return $this->request('get', $endpoint, ['query' => $query]);
    }

    /**
     * Execute a POST request against the Centralized Backend.
     */
    public function post(string $endpoint, array $data = []): ?array
    {
        return $this->request('post', $endpoint, ['json' => $data]);
    }

    /**
     * Execute a PUT request against the Centralized Backend.
     */
    public function put(string $endpoint, array $data = []): ?array
    {
        return $this->request('put', $endpoint, ['json' => $data]);
    }

    /**
     * Execute a PATCH request against the Centralized Backend.
     */
    public function patch(string $endpoint, array $data = []): ?array
    {
        return $this->request('patch', $endpoint, ['json' => $data]);
    }

    /**
     * Execute a DELETE request against the Centralized Backend.
     */
    public function delete(string $endpoint, array $data = []): ?array
    {
        return $this->request('delete', $endpoint, ['json' => $data]);
    }

    /**
     * Core unified HTTP execution handler with consistent headers, timeout, and robust error handling.
     */
    protected function request(string $method, string $endpoint, array $options = []): ?array
    {
        // Reset call status
        $this->lastStatusCode = null;
        $this->lastError = null;
        $this->lastRequestSuccessful = false;
        $this->lastRequestUnavailable = false;
        $this->lastResponseData = null;

        $cleanEndpoint = ltrim($endpoint, '/');
        $cleanBase = preg_replace('#/fleet/?$#i', '', $this->baseUrl);
        $url = rtrim($cleanBase, '/') . '/' . $cleanEndpoint;
        $token = session('central_user_token') ?? $this->getServiceToken();

        try {
            $req = Http::timeout($this->timeout)->acceptJson();

            if ($token) {
                $req = $req->withToken($token);
            }

            if (isset($options['query']) && !empty($options['query'])) {
                $req = $req->withQueryParameters($options['query']);
            }

            $response = match (strtolower($method)) {
                'get' => $req->get($url),
                'post' => $req->post($url, $options['json'] ?? []),
                'put' => $req->put($url, $options['json'] ?? []),
                'patch' => $req->patch($url, $options['json'] ?? []),
                'delete' => $req->delete($url, $options['json'] ?? []),
                default => throw new \InvalidArgumentException("Unsupported HTTP method [{$method}]"),
            };

            $this->lastStatusCode = $response->status();

            if ($response->successful()) {
                if ($response->status() === 204 || trim($response->body()) === '') {
                    $this->lastRequestSuccessful = true;
                    $this->lastResponseData = ['success' => true];
                    return ['success' => true];
                }

                $decoded = $response->json();
                if (is_array($decoded)) {
                    $this->lastRequestSuccessful = true;
                    $this->lastResponseData = $decoded;
                    return $decoded;
                }

                $this->lastRequestSuccessful = false;
                $this->lastError = 'Invalid JSON response received from centralized backend.';
                Log::error("Central API Error [{$method} {$url}]: Non-JSON payload received.");
                return null;
            }

            $this->lastRequestSuccessful = false;

            $rawMsg = $response->json('message') ?? $response->json('error');
            if (is_array($rawMsg)) {
                $baseMsg = json_encode($rawMsg);
            } elseif (is_string($rawMsg)) {
                $baseMsg = $rawMsg;
            } else {
                $baseMsg = null;
            }

            // Handle unauthorized: invalidate cached token
            if ($response->status() === 401) {
                Cache::forget('central_backend_jwt_token');
                $this->lastError = 'Unauthorized request to centralized backend (401).';
            } elseif ($response->status() === 403) {
                $this->lastError = 'Forbidden: Access denied by centralized backend (403).';
            } elseif ($response->status() === 404) {
                $this->lastError = $baseMsg ?? 'Requested centralized resource not found (404).';
            } elseif ($response->status() === 422 || $response->status() === 400) {
                $msg = $baseMsg ?? 'Validation failed on centralized backend.';
                $errors = $response->json('errors');
                if (is_array($errors) && !empty($errors)) {
                    $msg .= ' (' . implode('; ', array_map(fn($k, $v) => "$k: " . (is_array($v) ? implode(',', $v) : $v), array_keys($errors), $errors)) . ')';
                }
                $this->lastError = $msg;
            } elseif ($response->status() >= 500) {
                $this->lastError = $baseMsg ?? ('Centralized backend encountered an internal server error (' . $response->status() . ').');
            } else {
                $this->lastError = $baseMsg ?? ('Centralized backend returned HTTP ' . $response->status() . '.');
            }

            $sanitizedBody = $this->sanitizeForLog($response->body());
            Log::error("Central API Error [{$method} {$url}]: HTTP {$response->status()} - {$sanitizedBody}");
            return null;
        } catch (\Throwable $e) {
            $this->lastRequestSuccessful = false;
            $this->lastRequestUnavailable = true;
            $this->lastStatusCode = 0;
            $this->lastError = 'Centralized backend is unreachable or timed out (' . $e->getMessage() . ').';

            Log::error("Central API Connection Exception [{$method} {$url}]: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Prevent leaking passwords, secrets, or tokens into server log files.
     */
    protected function sanitizeForLog(string $body): string
    {
        $sanitized = preg_replace('/"(password|token|secret|current_password)"\s*:\s*"[^"]+"/i', '"$1":"***REDACTED***"', $body);
        return is_string($sanitized) ? $sanitized : '[unparseable body]';
    }
}
