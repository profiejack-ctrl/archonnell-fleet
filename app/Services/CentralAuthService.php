<?php

namespace App\Services;

class CentralAuthService
{
    protected CentralApiClient $client;

    public function __construct(CentralApiClient $client)
    {
        $this->client = $client;
    }

    public function getClient(): CentralApiClient
    {
        return $this->client;
    }

    public function login(string $email, string $password): ?array
    {
        $res = $this->client->post('auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        if (isset($res['data']['token'])) {
            session(['central_user_token' => $res['data']['token']]);
            session(['central_user_profile' => $res['data']['user'] ?? null]);
        }

        return $res['data'] ?? null;
    }

    public function register(array $data): ?array
    {
        $res = $this->client->post('auth/register', [
            'email' => $data['email'],
            'password' => $data['password'],
            'fullName' => $data['name'] ?? $data['fullName'] ?? '',
            'role' => $data['role'] ?? 'customer',
        ]);

        if (isset($res['data']['token'])) {
            session(['central_user_token' => $res['data']['token']]);
            session(['central_user_profile' => $res['data']['user'] ?? null]);
        }

        return $res['data'] ?? null;
    }

    public function getProfile(?string $token = null): ?array
    {
        $res = $this->client->get('auth/me');
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function logout(): void
    {
        try {
            $this->client->post('auth/logout');
        } catch (\Throwable) {
            // Silence logout error
        }
        session()->forget(['central_user_token', 'central_user_profile']);
    }

    public function getUsers(): ?array
    {
        $res = $this->client->get('auth/users');
        if ($res === null || !array_key_exists('data', $res)) {
            return null;
        }
        return (array) $res['data'];
    }

    public function createUser(array $data): ?array
    {
        $res = $this->client->post('auth/users', [
            'email' => $data['email'],
            'password' => $data['password'] ?? 'password123',
            'name' => $data['name'],
            'role' => $data['role'] ?? 'User',
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function updateUser(string $id, array $data): ?array
    {
        $res = $this->client->put("auth/users/{$id}", [
            'email' => $data['email'] ?? null,
            'name' => $data['name'] ?? null,
            'role' => $data['role'] ?? null,
            'status' => $data['status'] ?? null,
            'password' => $data['password'] ?? null,
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function getAuditLogs(?string $module = null, ?string $search = null): ?array
    {
        $query = [];
        if ($module) $query['module'] = $module;
        if ($search) $query['search'] = $search;

        $res = $this->client->get('auth/audit-logs', $query);
        if ($res === null || !array_key_exists('data', $res)) {
            return null;
        }
        return (array) $res['data'];
    }

    public function recordAuditLog(array $data): ?array
    {
        $res = $this->client->post('auth/audit-logs', [
            'module' => $data['module'] ?? 'Fleet',
            'action' => $data['action'] ?? '',
            'resource_id' => $data['resource_id'] ?? null,
            'details' => $data['details'] ?? null,
            'ip' => $data['ip'] ?? request()->ip(),
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function updateProfile(array $data): ?array
    {
        $res = $this->client->put('auth/profile', [
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }

    public function updatePassword(string $currentPassword, string $newPassword): ?array
    {
        $res = $this->client->put('auth/password', [
            'current_password' => $currentPassword,
            'new_password' => $newPassword,
        ]);
        if ($res === null) {
            return null;
        }
        return $res['data'] ?? $res;
    }
}
