<?php

namespace App\Services;

class FleetAnalyticsService
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

    public function getFleetAnalytics(): ?array
    {
        $res = $this->client->get('fleet/analytics/dashboard');
        if ($res === null) {
            return null;
        }
        return (array) ($res['analytics'] ?? $res['data'] ?? []);
    }
}
