<?php

namespace App\Services;

class NotificationService
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

    public function getNotifications(): ?array
    {
        $res = $this->client->get('fleet/notifications');
        if ($res === null) {
            return null;
        }
        return (array) ($res['notifications'] ?? $res['data'] ?? []);
    }

    public function markNotificationRead(string $id): ?array
    {
        return $this->client->put("fleet/notifications/{$id}/read");
    }

    public function markAllNotificationsRead(): ?array
    {
        return $this->client->put('fleet/notifications/read-all');
    }
}
