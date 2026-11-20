<?php

namespace App\Http\Controllers;

use App\Services\VehicleService;
use App\Services\DispatchService;
use App\Services\RouteService;
use App\Services\NotificationService;
use App\Services\FleetAnalyticsService;
use App\Support\Database;
use App\Support\OpenRouteServiceGeocoder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

class ApiController
{
    protected function vehicleService(): VehicleService
    {
        return app(VehicleService::class);
    }

    protected function dispatchService(): DispatchService
    {
        return app(DispatchService::class);
    }

    protected function routeService(): RouteService
    {
        return app(RouteService::class);
    }

    protected function notificationService(): NotificationService
    {
        return app(NotificationService::class);
    }

    protected function analyticsService(): FleetAnalyticsService
    {
        return app(FleetAnalyticsService::class);
    }

    public function __construct()
    {
        // Uses live fleet tables (vehicles, drivers, trip_records, alerts).
    }

    /**
     * Authenticate with Laravel guards and check role permissions.
     */
    public function authorizeRole(array $allowedRoles = []): array
    {
        $user = auth('sanctum')->user() ?? auth('web')->user();
        $userRole = $user?->role ?? '';

        if (!empty($allowedRoles) && !in_array($userRole, $allowedRoles, true)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Access Denied: Required role (' . implode(', ', $allowedRoles) . ') not assigned to current user.',
            ]);
            exit;
        }

        return ['id' => $user?->id, 'name' => $user?->name, 'role' => $userRole];
    }

    /**
     * GET /api/vehicles/live
     */
    public function getLiveVehicles(): void
    {
        $actor = $this->authorizeRole(['Driver', 'Dispatcher', 'Logistics Officer', 'Admin', 'User', 'Staff']);
        $service = $this->vehicleService();
        if (!$service->getClient()->isAvailable()) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Centralized backend is unavailable.',
                'vehicles' => [],
            ], 503);
            return;
        }

        $vehicles = $service->getLiveVehiclesTelemetry();
        if ($vehicles === null) {
            $this->jsonResponse([
                'success' => false,
                'error' => $service->getClient()->getLastError() ?? 'Failed to retrieve live vehicles telemetry from centralized backend.',
                'vehicles' => [],
            ], $service->getClient()->getLastStatusCode() ?: 500);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'vehicles' => $vehicles,
        ]);
        return;
    }

    /**
     * GET /api/trips/active
     */
    public function getActiveTrips(): void
    {
        $this->authorizeRole(['Driver', 'Dispatcher', 'Logistics Officer', 'Admin', 'User']);
        $service = $this->dispatchService();
        if (!$service->getClient()->isAvailable()) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Centralized backend is unavailable.',
                'count' => 0,
                'trips' => [],
            ], 503);
            return;
        }

        $trips = $service->getActiveDispatches();
        if ($trips === null) {
            $this->jsonResponse([
                'success' => false,
                'error' => $service->getClient()->getLastError() ?? 'Failed to retrieve active trips from centralized backend.',
                'count' => 0,
                'trips' => [],
            ], $service->getClient()->getLastStatusCode() ?: 500);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'count' => count($trips),
            'trips' => $trips,
        ]);
        return;
    }

    /**
     * GET /api/trip/{id}/route
     */
    public function getTripRoute(int $tripId): void
    {
        $actor = $this->authorizeRole(['Driver', 'Dispatcher', 'Logistics Officer', 'Admin', 'User', 'Staff']);
        $service = $this->routeService();
        if (!$service->getClient()->isAvailable()) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Centralized backend is unavailable.',
            ], 503);
            return;
        }

        $centralRoute = $service->getTripRoute((string)$tripId);
        if ($centralRoute) {
            $this->jsonResponse($centralRoute);
            return;
        }

        $this->jsonResponse([
            'success' => false,
            'error' => $service->getClient()->getLastError() ?? 'Failed to retrieve trip route from centralized backend.',
        ], $service->getClient()->getLastStatusCode() ?: 404);
        return;
    }

    /**
     * GET /api/trip/{id}/eta
     */
    public function getTripEta(int $tripId): void
    {
        $actor = $this->authorizeRole(['Driver', 'Dispatcher', 'Logistics Officer', 'Admin', 'User', 'Staff']);
        $service = $this->routeService();
        if (!$service->getClient()->isAvailable()) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Centralized backend is unavailable.',
            ], 503);
            return;
        }

        $centralEta = $service->getTripEta((string)$tripId);
        if ($centralEta) {
            $this->jsonResponse($centralEta);
            return;
        }

        $this->jsonResponse([
            'success' => false,
            'error' => $service->getClient()->getLastError() ?? 'Failed to retrieve trip ETA from centralized backend.',
        ], $service->getClient()->getLastStatusCode() ?: 404);
        return;
    }

    /**
     * POST /api/trip/start
     * Driver clicks 'Start Trip' to launch tracking for a vehicle dispatch
     */
    public function startTrip(): void
    {
        $actor = $this->authorizeRole(['Driver', 'User', 'Dispatcher', 'Admin']);
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;

        $service = $this->routeService();
        if (!$service->getClient()->isAvailable()) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Centralized backend is unavailable. Trip cannot be started.',
            ], 503);
            return;
        }

        $centralRes = $service->startTrip($data);
        if ($centralRes) {
            $this->jsonResponse($centralRes);
            return;
        }

        $this->jsonResponse([
            'success' => false,
            'error' => $service->getClient()->getLastError() ?? 'Failed to start trip on centralized backend.',
        ], $service->getClient()->getLastStatusCode() ?: 400);
        return;
    }

    /**
     * POST /api/location/update
     */
    public function updateLocation(): void
    {
        $actor = $this->authorizeRole(['Driver', 'User', 'Dispatcher', 'Admin']);
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;

        $service = $this->routeService();
        if (!$service->getClient()->isAvailable()) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Centralized backend is unavailable. Location cannot be updated.',
            ], 503);
            return;
        }

        $centralRes = $service->updateLocation($data);
        if ($centralRes) {
            $this->jsonResponse($centralRes);
            return;
        }

        $this->jsonResponse([
            'success' => false,
            'error' => $service->getClient()->getLastError() ?? 'Failed to update location on centralized backend.',
        ], $service->getClient()->getLastStatusCode() ?: 400);
        return;
    }

    public function completeMyDispatch(int $dispatchId): void
    {
        $actor = $this->authorizeRole(['Driver', 'User']);

        $dispatchService = $this->dispatchService();
        if (!$dispatchService->getClient()->isAvailable()) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Centralized backend is unavailable. Dispatch cannot be completed.',
            ], 503);
            return;
        }

        $centralRes = $dispatchService->completeDispatch((string)$dispatchId);
        if (!$centralRes && !$dispatchService->getClient()->wasSuccessful()) {
            $centralRes = $dispatchService->updateDispatchStatus((string)$dispatchId, 'Completed');
        }

        if (!$centralRes && !$dispatchService->getClient()->wasSuccessful()) {
            $this->jsonResponse([
                'success' => false,
                'error' => $dispatchService->getClient()->getLastError() ?? 'Failed to complete dispatch on centralized backend.',
            ], $dispatchService->getClient()->getLastStatusCode() ?: 400);
            return;
        }

        $now = now();
        DB::table('dispatches')
            ->where('id', $dispatchId)
            ->update([
                'status' => 'Completed',
                'updated_at' => $now,
            ]);

        $this->jsonResponse([
            'success' => true,
            'message' => 'Delivery marked as completed.',
            'dispatch_id' => $dispatchId,
            'status' => 'Completed',
        ]);
    }

    public function getMyActiveDispatches(): void
    {
        $actor = $this->authorizeRole(['Driver', 'User']);

        $dispatchService = $this->dispatchService();
        if (!$dispatchService->getClient()->isAvailable()) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Centralized backend is unavailable.',
                'dispatches' => [],
            ], 503);
            return;
        }

        $centralDispatches = $dispatchService->getActiveDispatches();
        if ($centralDispatches === null) {
            $this->jsonResponse([
                'success' => false,
                'error' => $dispatchService->getClient()->getLastError() ?? 'Failed to retrieve active dispatches from centralized backend.',
                'dispatches' => [],
            ], $dispatchService->getClient()->getLastStatusCode() ?: 500);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'dispatches' => $centralDispatches,
        ]);
    }

    /**
     * GET /api/analytics/dashboard
     */
    public function getDashboardAnalytics(): void
    {
        $this->authorizeRole(['Driver', 'Dispatcher', 'Logistics Officer', 'Admin', 'User']);

        $service = $this->analyticsService();
        if (!$service->getClient()->isAvailable()) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Centralized backend is unavailable.',
            ], 503);
            return;
        }

        $analytics = $service->getFleetAnalytics();
        if ($analytics !== null) {
            $this->jsonResponse([
                'success' => true,
                'analytics' => $analytics,
            ]);
            return;
        }

        $this->jsonResponse([
            'success' => false,
            'error' => $service->getClient()->getLastError() ?? 'Failed to retrieve analytics from centralized backend.',
        ], $service->getClient()->getLastStatusCode() ?: 500);
        return;
    }

    /**
     * GET /api/notifications
     */
    public function getNotifications(): void
    {
        $this->authorizeRole(['Driver', 'Dispatcher', 'Logistics Officer', 'Admin', 'User']);

        $service = $this->notificationService();
        if (!$service->getClient()->isAvailable()) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Centralized backend is unavailable.',
                'notifications' => [],
            ], 503);
            return;
        }

        $notifications = $service->getNotifications();
        if ($notifications !== null) {
            $this->jsonResponse([
                'success' => true,
                'notifications' => $notifications,
            ]);
            return;
        }

        $this->jsonResponse([
            'success' => false,
            'error' => $service->getClient()->getLastError() ?? 'Failed to retrieve notifications from centralized backend.',
            'notifications' => [],
        ], $service->getClient()->getLastStatusCode() ?: 500);
        return;
    }

    /**
     * POST /api/integration/system
     * Interface to exchange data with Logistics 1, HR3, HR4, Financial Management System
     */
    public function handleSystemIntegration(): void
    {
        $this->authorizeRole(['Dispatcher', 'Logistics Officer', 'Admin']);
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;

        $service = $this->routeService();
        if (!$service->getClient()->isAvailable()) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Centralized backend is unavailable. Integration cannot proceed.',
            ], 503);
            return;
        }

        $res = $service->handleSystemIntegration($data);
        if ($res) {
            $this->jsonResponse($res);
            return;
        }

        $this->jsonResponse([
            'success' => false,
            'error' => $service->getClient()->getLastError() ?? 'Cross-system integration request failed on centralized backend.',
        ], $service->getClient()->getLastStatusCode() ?: 500);
        return;
    }

    // Helper utilities
    private function computeEtaDetails(array $trip, ?array $loc, ?array $routeResponse = null): array
    {
        // Prefer TomTom's traffic-aware travel time for ETA. OpenRouteService remains
        // the fallback and continues to provide the existing route geometry.
        try {
            $tomTomEta = $this->requestTomTomEtaForTrip($trip, $loc);
            if ($tomTomEta !== null) {
                return $tomTomEta;
            }
        } catch (\Throwable $exception) {
            Log::warning('TomTom traffic-aware ETA request failed; using OpenRouteService fallback.', [
                'trip_record_id' => $trip['id'] ?? null,
                'exception' => $exception->getMessage(),
            ]);
        }

        $routeResponse = $routeResponse ?? $this->requestOpenRouteServiceRouteForTrip($trip, $loc);
        $summary = $this->getOpenRouteServiceSummary($routeResponse);
        if (!is_array($summary) || !is_numeric($summary['distance'] ?? null) || !is_numeric($summary['duration'] ?? null)) {
            throw new \RuntimeException('OpenRouteService response contains no valid route distance and duration.');
        }

        $remainingDistKm = round((float) $summary['distance'] / 1000, 1);
        $timeMins = max(0, (int) round((float) $summary['duration'] / 60));
        $expectedArrivalTs = time() + ($timeMins * 60);

        return [
            'remaining_distance_km' => $remainingDistKm,
            'remaining_travel_time_mins' => $timeMins,
            'remaining_time_formatted' => $this->formatRouteTravelTime($timeMins),
            'expected_arrival_time' => date('h:i A', $expectedArrivalTs),
            'expected_arrival_timestamp' => date('Y-m-d H:i:s', $expectedArrivalTs),
            'source' => 'openrouteservice_fallback',
            'traffic_aware' => false,
            'traffic_delay_mins' => null,
        ];
    }

    /**
     * Request a current traffic-aware ETA from TomTom. Returns null when no
     * TomTom key is configured so existing deployments continue to work.
     */
    private function requestTomTomEtaForTrip(array $trip, ?array $loc): ?array
    {
        $apiKey = getenv('TOMTOM_API_KEY');
        if ($apiKey === false || trim($apiKey) === '') {
            return null;
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('TomTom routing requires the PHP cURL extension.');
        }

        $currentLat = $loc['latitude'] ?? null;
        $currentLng = $loc['longitude'] ?? null;
        $hasValidCurrentLocation = is_numeric($currentLat) && is_numeric($currentLng)
            && (float) $currentLat >= -90 && (float) $currentLat <= 90
            && (float) $currentLng >= -180 && (float) $currentLng <= 180;

        $startLat = $hasValidCurrentLocation ? (float) $currentLat : (float) $trip['origin_lat'];
        $startLng = $hasValidCurrentLocation ? (float) $currentLng : (float) $trip['origin_lng'];
        $destLat = (float) $trip['dest_lat'];
        $destLng = (float) $trip['dest_lng'];

        $url = 'https://api.tomtom.com/routing/1/calculateRoute/'
            . sprintf('%.6F,%.6F:%.6F,%.6F', $startLat, $startLng, $destLat, $destLng)
            . '/json?' . http_build_query([
                'traffic' => 'true',
                'travelMode' => 'car',
                'routeType' => 'fastest',
                'key' => trim($apiKey),
            ]);

        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize the TomTom routing request.');
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ]);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($responseBody)) {
            throw new \RuntimeException('TomTom routing HTTP request failed: ' . $curlError);
        }

        $response = json_decode($responseBody, true);
        if ($httpStatus < 200 || $httpStatus >= 300) {
            // Do not include the request URL: it contains the private API key.
            $message = is_array($response)
                ? ($response['error']['description'] ?? $response['error']['message'] ?? $response['message'] ?? 'No details provided.')
                : 'No details provided.';
            throw new \RuntimeException("TomTom returned HTTP {$httpStatus}: {$message}");
        }

        $summary = $response['routes'][0]['summary'] ?? null;
        if (!is_array($summary)
            || !is_numeric($summary['travelTimeInSeconds'] ?? null)
            || !is_numeric($summary['lengthInMeters'] ?? null)) {
            throw new \RuntimeException('TomTom returned no valid route travel time or distance.');
        }

        $timeSeconds = max(0, (int) round((float) $summary['travelTimeInSeconds']));
        $timeMins = (int) round($timeSeconds / 60);
        $expectedArrivalTs = time() + $timeSeconds;
        $trafficDelaySeconds = is_numeric($summary['trafficDelayInSeconds'] ?? null)
            ? max(0, (int) $summary['trafficDelayInSeconds'])
            : null;

        return [
            'remaining_distance_km' => round((float) $summary['lengthInMeters'] / 1000, 1),
            'remaining_travel_time_mins' => $timeMins,
            'remaining_time_formatted' => $this->formatRouteTravelTime($timeMins),
            'expected_arrival_time' => date('h:i A', $expectedArrivalTs),
            'expected_arrival_timestamp' => date('Y-m-d H:i:s', $expectedArrivalTs),
            'source' => 'tomtom',
            'traffic_aware' => true,
            'traffic_delay_mins' => $trafficDelaySeconds !== null ? (int) round($trafficDelaySeconds / 60) : null,
        ];
    }

    /**
     * Request TomTom route alternatives using current traffic information.
     * Normalize the response to the same shape consumed by the existing route UI.
     */
    private function requestTomTomRoutesForTrip(array $trip, ?array $loc): array
    {
        $apiKey = getenv('TOMTOM_API_KEY');
        if ($apiKey === false || trim($apiKey) === '') {
            throw new \RuntimeException('TomTom API key is missing (TOMTOM_API_KEY).');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('TomTom routing requires the PHP cURL extension.');
        }

        $currentLat = $loc['latitude'] ?? null;
        $currentLng = $loc['longitude'] ?? null;
        $hasValidCurrentLocation = is_numeric($currentLat) && is_numeric($currentLng)
            && (float) $currentLat >= -90 && (float) $currentLat <= 90
            && (float) $currentLng >= -180 && (float) $currentLng <= 180;
        $startLat = $hasValidCurrentLocation ? (float) $currentLat : (float) $trip['origin_lat'];
        $startLng = $hasValidCurrentLocation ? (float) $currentLng : (float) $trip['origin_lng'];
        $destLat = (float) $trip['dest_lat'];
        $destLng = (float) $trip['dest_lng'];

        $url = 'https://api.tomtom.com/routing/1/calculateRoute/'
            . sprintf('%.6F,%.6F:%.6F,%.6F', $startLat, $startLng, $destLat, $destLng)
            . '/json?' . http_build_query([
                'traffic' => 'true',
                'travelMode' => 'car',
                'routeType' => 'fastest',
                'maxAlternatives' => 2,
                'key' => trim($apiKey),
            ]);

        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize the TomTom route-options request.');
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($responseBody)) {
            throw new \RuntimeException('TomTom route-options request failed: ' . $curlError);
        }
        $response = json_decode($responseBody, true);
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $message = is_array($response)
                ? ($response['error']['description'] ?? $response['error']['message'] ?? $response['message'] ?? 'No details provided.')
                : 'No details provided.';
            throw new \RuntimeException("TomTom route options returned HTTP {$httpStatus}: {$message}");
        }

        $tomTomRoutes = $response['routes'] ?? null;
        if (!is_array($tomTomRoutes) || $tomTomRoutes === []) {
            throw new \RuntimeException('TomTom returned no route options.');
        }

        $normalizedRoutes = [];
        foreach (array_slice($tomTomRoutes, 0, 3) as $route) {
            $summary = $route['summary'] ?? null;
            $legs = $route['legs'] ?? null;
            $points = is_array($legs) && isset($legs[0]['points']) ? $legs[0]['points'] : null;
            if (!is_array($summary)
                || !is_numeric($summary['lengthInMeters'] ?? null)
                || !is_numeric($summary['travelTimeInSeconds'] ?? null)
                || !is_array($points)
                || $points === []) {
                continue;
            }

            $geometry = [];
            foreach ($points as $point) {
                if (is_array($point)
                    && is_numeric($point['longitude'] ?? null)
                    && is_numeric($point['latitude'] ?? null)) {
                    $geometry[] = [(float) $point['longitude'], (float) $point['latitude']];
                }
            }
            if ($geometry === []) {
                continue;
            }

            $normalizedRoutes[] = [
                'summary' => [
                    'distance' => (float) $summary['lengthInMeters'],
                    'duration' => (float) $summary['travelTimeInSeconds'],
                    'source' => 'tomtom',
                    'traffic_aware' => true,
                    'traffic_delay_seconds' => is_numeric($summary['trafficDelayInSeconds'] ?? null)
                        ? max(0, (int) $summary['trafficDelayInSeconds'])
                        : null,
                ],
                'geometry' => $geometry,
            ];
        }

        if ($normalizedRoutes === []) {
            throw new \RuntimeException('TomTom returned no usable route geometry or travel times.');
        }

        return ['routes' => $normalizedRoutes, 'source' => 'tomtom', 'traffic_aware' => true];
    }

    private function calculateRouteColor(array $trip, float $speed): string
    {
        if ($trip['status'] === 'Critical Delay' || $speed < 10.0) {
            return 'red';
        } elseif ($trip['status'] === 'Delayed' || $speed < 25.0) {
            return 'yellow';
        }
        return 'green';
    }

    private function requestOpenRouteServiceRouteForTrip(array $trip, ?array $loc): array
    {
        $currentLat = $loc['latitude'] ?? null;
        $currentLng = $loc['longitude'] ?? null;
        $hasValidCurrentLocation = is_numeric($currentLat) && is_numeric($currentLng) &&
            (float)$currentLat >= -90 && (float)$currentLat <= 90 &&
            (float)$currentLng >= -180 && (float)$currentLng <= 180;

        return $this->requestOpenRouteServiceRoute(
            $hasValidCurrentLocation ? (float)$currentLat : (float)$trip['origin_lat'],
            $hasValidCurrentLocation ? (float)$currentLng : (float)$trip['origin_lng'],
            (float)$trip['dest_lat'],
            (float)$trip['dest_lng']
        );
    }

    private function getOpenRouteServiceSummary(array $response): ?array
    {
        $routes = $response['routes'] ?? null;
        if (is_array($routes) && isset($routes[0]) && is_array($routes[0])) {
            $summary = $routes[0]['summary'] ?? null;
            if (is_array($summary)) {
                return $summary;
            }
        }

        $features = $response['features'] ?? null;
        if (is_array($features) && isset($features[0]) && is_array($features[0])) {
            $properties = $features[0]['properties'] ?? null;
            if (is_array($properties) && is_array($properties['summary'] ?? null)) {
                return $properties['summary'];
            }
        }

        return null;
    }

    private function formatRouteTravelTime(int $timeMins): string
    {
        $hours = intdiv($timeMins, 60);
        $minutes = $timeMins % 60;

        if ($hours > 0) {
            return "{$hours} hr" . ($minutes > 0 ? " {$minutes} mins" : '');
        }

        return "{$timeMins} mins";
    }

    private function requestOpenRouteServiceRoute(float $startLat, float $startLng, float $destLat, float $destLng): array
    {
        $apiKey = getenv('OPENROUTESERVICE_API_KEY');
        if ($apiKey === false || trim($apiKey) === '') {
            throw new \RuntimeException('OpenRouteService API key is missing (OPENROUTESERVICE_API_KEY).');
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('OpenRouteService routing requires the PHP cURL extension.');
        }

        $requestBody = json_encode([
            'coordinates' => [
                [$startLng, $startLat],
                [$destLng, $destLat],
            ],
            'alternative_routes' => [
                'target_count' => 3,
            ],
        ]);
        if ($requestBody === false) {
            throw new \RuntimeException('Unable to encode the OpenRouteService routing request.');
        }

        $curl = curl_init('https://api.heigit.org/openrouteservice/v2/directions/driving-car');
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize the OpenRouteService HTTP request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($responseBody === false) {
            throw new \RuntimeException('OpenRouteService HTTP request failed: ' . $curlError);
        }

        $response = json_decode($responseBody, true);
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $apiError = is_array($response)
                ? ($response['error']['message'] ?? $response['message'] ?? 'No details provided.')
                : 'No details provided.';
            throw new \RuntimeException("OpenRouteService returned HTTP {$httpStatus}: {$apiError}");
        }

        if (!is_array($response)) {
            throw new \RuntimeException('OpenRouteService returned an invalid JSON response.');
        }

        if (isset($response['error'])) {
            $apiError = $response['error']['message'] ?? $response['message'] ?? 'No details provided.';
            throw new \RuntimeException('OpenRouteService returned an API error: ' . $apiError);
        }

        return $response;
    }

    private function generateRouteWaypoints(array $trip, array $loc, ?array $routeResponse = null): array
    {
        $routeResponse = $routeResponse ?? $this->requestOpenRouteServiceRouteForTrip($trip, $loc);
        $coordinates = null;
        $features = $routeResponse['features'] ?? null;
        $feature = is_array($features) ? ($features[0] ?? null) : null;
        if (is_array($feature) && is_array($feature['geometry'] ?? null)) {
            $coordinates = $feature['geometry']['coordinates'] ?? null;
        }

        $routes = $routeResponse['routes'] ?? null;
        $primaryRoute = is_array($routes) ? ($routes[0] ?? null) : null;
        $encodedGeometry = is_array($primaryRoute) ? ($primaryRoute['geometry'] ?? null) : null;
        if (is_string($encodedGeometry) && $encodedGeometry !== '') {
            $coordinates = [];
            $index = 0;
            $latitude = 0;
            $longitude = 0;
            $encodedLength = strlen($encodedGeometry);

            while ($index < $encodedLength) {
                $deltas = [];
                for ($axis = 0; $axis < 2; $axis++) {
                    $result = 0;
                    $shift = 0;
                    do {
                        if ($index >= $encodedLength) {
                            throw new \RuntimeException('OpenRouteService returned invalid route geometry.');
                        }

                        $byte = ord($encodedGeometry[$index++]) - 63;
                        if ($byte < 0 || $byte > 63) {
                            throw new \RuntimeException('OpenRouteService returned invalid route geometry.');
                        }

                        $result |= ($byte & 0x1f) << $shift;
                        $shift += 5;
                    } while ($byte >= 0x20);

                    $deltas[] = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                }

                $latitude += $deltas[0];
                $longitude += $deltas[1];
                $coordinates[] = [$longitude / 100000, $latitude / 100000];
            }
        } elseif (is_array($encodedGeometry)) {
            $coordinates = $encodedGeometry;
        }

        if (!is_array($coordinates) || $coordinates === []) {
            throw new \RuntimeException('OpenRouteService response contains no route geometry.');
        }

        $waypoints = [];
        foreach ($coordinates as $coordinate) {
            if (!is_array($coordinate) || !isset($coordinate[0], $coordinate[1]) ||
                !is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) {
                throw new \RuntimeException('OpenRouteService returned invalid route geometry.');
            }

            $waypoints[] = ['lat' => (float)$coordinate[1], 'lng' => (float)$coordinate[0]];
        }

        return $waypoints;
    }

    private function buildAlternativeRoutes(array $response, array $trip, array $loc, array $primaryWaypoints): array
    {
        $candidates = [];
        $routeFormat = 'routes';
        $orsRoutes = $response['routes'] ?? null;
        if (is_array($orsRoutes)) {
            foreach ($orsRoutes as $index => $route) {
                if (is_array($route)) {
                    $candidates[] = ['index' => (int)$index, 'route' => $route];
                }
            }
        }

        if ($candidates === []) {
            $routeFormat = 'features';
            $features = $response['features'] ?? null;
            if (is_array($features)) {
                foreach ($features as $index => $feature) {
                    if (is_array($feature)) {
                        $candidates[] = ['index' => (int)$index, 'route' => $feature];
                    }
                }
            }
        }

        // ORS returns the selected primary route first; retain that ordering in the API response.
        if ($candidates === [] || $candidates[0]['index'] !== 0) {
            return [];
        }

        $routeOptions = [];
        foreach ($candidates as $candidate) {
            if ($candidate['index'] >= 3) {
                continue;
            }

            $route = $candidate['route'];
            if ($routeFormat === 'routes') {
                $summary = $route['summary'] ?? null;
            } else {
                $properties = $route['properties'] ?? null;
                $summary = is_array($properties) ? ($properties['summary'] ?? null) : null;
            }

            if (!is_array($summary) || !is_numeric($summary['distance'] ?? null) ||
                !is_numeric($summary['duration'] ?? null) || (float)$summary['distance'] < 0 ||
                (float)$summary['duration'] < 0) {
                continue;
            }

            if ($candidate['index'] === 0) {
                $waypoints = $primaryWaypoints;
            } else {
                $candidateResponse = [$routeFormat => [$route]];
                try {
                    $waypoints = $this->generateRouteWaypoints($trip, $loc, $candidateResponse);
                } catch (\RuntimeException $exception) {
                    continue;
                }
            }

            $timeMins = (int)round((float)$summary['duration'] / 60);
            $routeOptions[] = [
                'route_number' => count($routeOptions) + 1,
                'route_index' => $candidate['index'],
                'waypoints' => $waypoints,
                'distance_km' => round((float)$summary['distance'] / 1000, 1),
                'travel_time_mins' => $timeMins,
                'formatted_travel_time' => $this->formatRouteTravelTime($timeMins),
                'source' => $summary['source'] ?? ($response['source'] ?? 'openrouteservice'),
                'traffic_aware' => $summary['traffic_aware'] ?? ($response['traffic_aware'] ?? false),
                'traffic_delay_mins' => isset($summary['traffic_delay_seconds']) && is_numeric($summary['traffic_delay_seconds'])
                    ? (int) round(((float) $summary['traffic_delay_seconds']) / 60)
                    : null,
            ];
        }

        return $routeOptions;
    }

    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadiusKm * $c;
    }

    private function findActiveTripForVehicle(array $state, int $vehicleId): ?array
    {
        foreach ($state['trips'] as $t) {
            if ($t['vehicle_id'] === $vehicleId && $t['status'] !== 'Completed') {
                return $t;
            }
        }
        return null;
    }

    private function actorCanAccessTrip(array $trip, array $actor): bool
    {
        return $actor['role'] === 'Admin' || DB::table('drivers')
            ->where('id', $trip['driver_id'])
            ->where('user_id', $actor['id'])
            ->exists();
    }

    private function ensureStorage(): void
    {
        // Legacy no-op: the fleet is backed directly by the database now.
    }

    private function getFleetState(): array
    {
        $vehicles = [];
        foreach (DB::table('vehicles')->get() as $v) {
            $vehicles[(int)$v->id] = [
                'id' => (int)$v->id,
                'vehicle_code' => $v->vehicle_code ?? ($v->name ?? (string)$v->id),
                'plate_number' => $v->plate_number,
                'type' => $v->type,
                'status' => $v->status,
                'fuel_level' => (float)$v->fuel_level,
            ];
        }

        $drivers = [];
        foreach (DB::table('drivers')->leftJoin('users', 'drivers.user_id', '=', 'users.id')->get(['drivers.*', 'users.name as user_name']) as $d) {
            $drivers[(int)$d->id] = [
                'id' => (int)$d->id,
                'name' => $d->user_name ?: ($d->name ?? 'Driver'),
                'employee_id' => $d->employee_id,
                'role' => $d->role,
                'score' => (float)$d->score,
            ];
        }

        $locations = [];
        foreach (DB::table('location_logs')->orderByDesc('timestamp')->orderByDesc('id')->get() as $l) {
            $vehicleId = (int)$l->vehicle_id;
            if (isset($locations[$vehicleId])) {
                continue;
            }
            $locations[$vehicleId] = [
                'latitude' => $l->latitude !== null ? (float)$l->latitude : null,
                'longitude' => $l->longitude !== null ? (float)$l->longitude : null,
                'speed' => (float)($l->speed ?? 0),
                'heading' => isset($l->heading) && $l->heading !== null ? (float)$l->heading : null,
                'fuel_level' => $l->fuel_level !== null ? (float)$l->fuel_level : null,
                'timestamp' => (string)$l->timestamp,
            ];
        }

        $trips = [];
        foreach (DB::table('trip_records')->get() as $t) {
            $trips[] = [
                'id' => (int)$t->id,
                'vehicle_id' => (int)$t->vehicle_id,
                'driver_id' => (int)$t->driver_id,
                'origin' => $t->origin,
                'destination' => $t->destination,
                'origin_lat' => $t->origin_lat !== null ? (float)$t->origin_lat : null,
                'origin_lng' => $t->origin_lng !== null ? (float)$t->origin_lng : null,
                'dest_lat' => $t->dest_lat !== null ? (float)$t->dest_lat : null,
                'dest_lng' => $t->dest_lng !== null ? (float)$t->dest_lng : null,
                'departure_time' => (string)$t->departure_time,
                'estimated_arrival' => $t->estimated_arrival !== null ? (string)$t->estimated_arrival : null,
                'actual_arrival' => $t->actual_arrival !== null ? (string)$t->actual_arrival : null,
                'total_distance' => $t->total_distance !== null ? (float)$t->total_distance : 0.0,
                'total_duration' => (int)($t->total_duration ?? 0),
                'fuel_consumption' => $t->fuel_consumption !== null ? (float)$t->fuel_consumption : 0.0,
                'status' => $t->status,
            ];
        }

        $notifications = [];
        foreach (DB::table('alerts')->orderByDesc('id')->limit(50)->get() as $a) {
            $notifications[] = [
                'id' => (int)$a->id,
                'trip_id' => $a->trip_record_id !== null ? (int)$a->trip_record_id : null,
                'vehicle_id' => $a->vehicle_id !== null ? (int)$a->vehicle_id : null,
                'type' => $a->type ?: ($a->title ?: 'Notice'),
                'message' => $a->message ?: ($a->detail ?: ''),
                'severity' => $a->severity ?? 'info',
                'created_at' => (string)$a->created_at,
            ];
        }
        $notifications = array_reverse($notifications);

        return [
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'locations' => $locations,
            'trips' => $trips,
            'notifications' => $notifications,
        ];
    }

    private function saveFleetState(array $state): void
    {
        // Deprecated: state mutations are persisted directly via DB updates.
    }

    /**
     * Geocode a free-text address with the existing OpenRouteService key.
     * Returns ['lat' => float, 'lng' => float] or null.
     */
    private function geocodeAddress(string $address): ?array
    {
        return app(OpenRouteServiceGeocoder::class)->geocode($address);
    }

    /**
     * Ensure a trip has origin/destination coordinates; geocode missing ones
     * and persist them to the trip record (and linked dispatch).
     *
     * @return array{origin_lat: float, origin_lng: float, dest_lat: float, dest_lng: float}|null
     */
    private function ensureTripCoordinates(array $trip): ?array
    {
        $geocoder = app(OpenRouteServiceGeocoder::class);
        $originLat = $trip['origin_lat'] ?? null;
        $originLng = $trip['origin_lng'] ?? null;
        $destLat = $trip['dest_lat'] ?? null;
        $destLng = $trip['dest_lng'] ?? null;

        $hasOrigin = $geocoder->hasValidCoordinates($originLat, $originLng);
        $hasDest = $geocoder->hasValidCoordinates($destLat, $destLng);
        $failedLocations = [];

        if (!$hasOrigin && !empty($trip['origin'])) {
            $geo = $this->geocodeAddress((string)$trip['origin']);
            if ($geo !== null) {
                $originLat = $geo['lat'];
                $originLng = $geo['lng'];
                $hasOrigin = true;
            }
        }
        if (!$hasDest && !empty($trip['destination'])) {
            $geo = $this->geocodeAddress((string)$trip['destination']);
            if ($geo !== null) {
                $destLat = $geo['lat'];
                $destLng = $geo['lng'];
                $hasDest = true;
            }
        }

        if (!$hasOrigin) {
            $failedLocations[] = 'origin';
        }
        if (!$hasDest) {
            $failedLocations[] = 'destination';
        }
        if ($failedLocations !== []) {
            Log::warning('Unable to geocode TripRecord location(s).', [
                'trip_record_id' => $trip['id'] ?? null,
                'failed_locations' => $failedLocations,
                'origin' => $trip['origin'] ?? null,
                'destination' => $trip['destination'] ?? null,
            ]);
            return null;
        }

        DB::table('trip_records')->where('id', $trip['id'])->update([
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'dest_lat' => $destLat,
            'dest_lng' => $destLng,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (!empty($trip['dispatch_id'])) {
            DB::table('dispatches')->where('id', $trip['dispatch_id'])->update([
                'origin_lat' => $originLat,
                'origin_lng' => $originLng,
                'dest_lat' => $destLat,
                'dest_lng' => $destLng,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return [
            'origin_lat' => (float)$originLat,
            'origin_lng' => (float)$originLng,
            'dest_lat' => (float)$destLat,
            'dest_lng' => (float)$destLng,
        ];
    }

    private function jsonResponse(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    /* ════════════════════════════════════════════════════════
       DRIVER ANALYTICS MODULE
       ════════════════════════════════════════════════════════ */

    /**
     * Compute driver performance score using weighted formula:
     * 40% On-Time | 30% Fuel Efficiency | 20% Safety | 10% Attendance
     */
    private function computeDriverScore(float $onTime, float $fuel, float $safety, float $attendance): float
    {
        return round(($onTime * 0.40) + ($fuel * 0.30) + ($safety * 0.20) + ($attendance * 0.10), 1);
    }

    /**
     * Build seeded driver performance dataset (replaces real DB query).
     */
    private function buildDriverDataset(): array
    {
        $rows = DB::table('drivers')
            ->leftJoin('users', 'drivers.user_id', '=', 'users.id')
            ->leftJoin('trip_records', 'trip_records.driver_id', '=', 'drivers.id')
            ->select(
                'drivers.id',
                'users.name as user_name',
                'drivers.name as driver_name',
                'drivers.employee_id as eid',
                'drivers.role',
                'drivers.status',
                'drivers.score',
                DB::raw('COUNT(trip_records.id) as trips'),
                DB::raw("SUM(CASE WHEN trip_records.status = 'Completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN trip_records.status IS NOT NULL AND LOWER(trip_records.status) LIKE '%delay%' THEN 1 ELSE 0 END) as delayed"),
                DB::raw('COALESCE(SUM(trip_records.total_distance), 0) as distance'),
                DB::raw('COALESCE(SUM(trip_records.fuel_consumption), 0) as fuel')
            )
            ->groupBy('drivers.id', 'users.name', 'drivers.name', 'drivers.employee_id', 'drivers.role', 'drivers.status', 'drivers.score')
            ->orderByDesc('drivers.score')
            ->get();

        $dataset = [];
        foreach ($rows as $i => $row) {
            $name = $row->user_name ?: ($row->driver_name ?: 'Driver');
            $score = (float)($row->score ?? 0);
            $distance = (float)$row->distance;
            $fuel = (float)$row->fuel;

            $dataset[] = [
                'id' => (int)$row->id,
                'name' => $name,
                'eid' => $row->eid,
                'role' => $row->role,
                'status' => $row->status,
                'on_time' => 0,
                'fuel' => 0,
                'safety' => 0,
                'attendance' => 0,
                'trips' => (int)$row->trips,
                'completed' => (int)$row->completed,
                'delayed' => (int)$row->delayed,
                'km_l' => $fuel > 0 ? round($distance / $fuel, 1) : 0,
                'risk' => $score >= 90 ? 'Low' : ($score >= 70 ? 'Medium' : 'High'),
                'score' => round($score, 1),
                'rank' => $i + 1,
            ];
        }

        return $dataset;
    }

    /**
     * GET /api/driver/dashboard
     * Returns KPI summary and monthly trend data.
     */
    public function getDriverDashboard(): void
    {
        $this->authorizeRole(['Driver', 'Dispatcher', 'Logistics Officer', 'Admin', 'User']);
        $drivers = $this->buildDriverDataset();

        if (empty($drivers)) {
            $this->jsonResponse([
                'success' => true,
                'kpis' => [
                    'total_drivers'   => 0,
                    'active_drivers'  => 0,
                    'avg_score'       => 0,
                    'top_driver'      => ['name' => 'N/A', 'score' => 0, 'id' => ''],
                    'lowest_driver'   => ['name' => 'N/A', 'score' => 0, 'id' => ''],
                    'total_trips'     => 0,
                ],
                'monthly_trend' => ['labels' => [], 'scores' => [], 'trips' => [], 'km_l' => []],
            ]);
            return;
        }

        $scores    = array_column($drivers, 'score');
        $avgScore  = round(array_sum($scores) / count($scores), 1);
        $top       = $drivers[0];
        $lowest    = $drivers[count($drivers) - 1];
        $totalTrips= array_sum(array_column($drivers, 'trips'));
        $activeDrivers = count(array_filter($drivers, fn($d) => strtolower((string)($d['status'] ?? '')) === 'active'));

        // Build real monthly aggregates from trips.
        $trendLabels = [];
        $trendScores = [];
        $trendTrips  = [];
        $trendKmL    = [];
        for ($i = 7; $i >= 0; $i--) {
            $monthStart = date('Y-m-01', strtotime("first day of -{$i} month"));
            $monthEnd   = date('Y-m-t', strtotime($monthStart));
            $agg = DB::table('trip_records')
                ->whereBetween('created_at', [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59'])
                ->selectRaw('COUNT(*) as trips, COALESCE(SUM(total_distance),0) as distance, COALESCE(SUM(fuel_consumption),0) as fuel')
                ->first();

            $trendLabels[] = date('M', strtotime($monthStart));
            $trendScores[] = $avgScore;
            $trendTrips[]  = (int)($agg->trips ?? 0);
            $trendKmL[]    = ($agg->fuel ?? 0) > 0 ? round(((float)$agg->distance) / (float)$agg->fuel, 1) : 0;
        }

        $this->jsonResponse([
            'success' => true,
            'kpis' => [
                'total_drivers'   => count($drivers),
                'active_drivers'  => $activeDrivers,
                'avg_score'       => $avgScore,
                'top_driver'      => ['name' => $top['name'], 'score' => $top['score'], 'id' => $top['eid']],
                'lowest_driver'   => ['name' => $lowest['name'], 'score' => $lowest['score'], 'id' => $lowest['eid']],
                'total_trips'     => $totalTrips,
            ],
            'monthly_trend' => [
                'labels' => $trendLabels,
                'scores' => $trendScores,
                'trips'  => $trendTrips,
                'km_l'   => $trendKmL,
            ],
        ]);
    }

    /**
     * GET /api/driver/rankings?period=monthly|quarterly&type=top|bottom
     */
    public function getDriverRankings(): void
    {
        $this->authorizeRole(['Driver', 'Dispatcher', 'Logistics Officer', 'Admin', 'User']);
        $period = $_GET['period'] ?? 'monthly';
        $type   = $_GET['type']   ?? 'top';
        $limit  = (int)($_GET['limit'] ?? 10);

        $drivers = $this->buildDriverDataset();

        if ($type === 'bottom') {
            $drivers = array_reverse($drivers);
        }

        $drivers = array_slice($drivers, 0, $limit);

        $this->jsonResponse([
            'success' => true,
            'period'  => $period,
            'type'    => $type,
            'rankings'=> $drivers,
        ]);
    }

    /**
     * GET /api/driver/{id}/performance
     */
    public function getDriverPerformance(int $driverId): void
    {
        $this->authorizeRole(['Driver', 'Dispatcher', 'Logistics Officer', 'Admin', 'User']);
        $drivers = $this->buildDriverDataset();

        $driver = null;
        foreach ($drivers as $d) {
            if ($d['id'] === $driverId) { $driver = $d; break; }
        }

        if (!$driver) {
            $this->jsonResponse(['success' => false, 'error' => 'Driver not found'], 404);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'driver'  => $driver,
            'score_breakdown' => [
                'on_time_delivery' => ['value' => $driver['on_time'], 'weight' => 40, 'weighted' => round($driver['on_time'] * 0.40, 1)],
                'fuel_efficiency'  => ['value' => $driver['fuel'],   'weight' => 30, 'weighted' => round($driver['fuel']    * 0.30, 1)],
                'safety_score'     => ['value' => $driver['safety'], 'weight' => 20, 'weighted' => round($driver['safety']  * 0.20, 1)],
                'attendance'       => ['value' => $driver['attendance'], 'weight' => 10, 'weighted' => round($driver['attendance'] * 0.10, 1)],
                'composite'        => $driver['score'],
            ],
        ]);
    }

    /**
     * GET /api/driver/analytics
     * Returns aggregated analytics for charts.
     */
    public function getDriverAnalytics(): void
    {
        $this->authorizeRole(['Driver', 'Dispatcher', 'Logistics Officer', 'Admin', 'User']);
        $drivers = $this->buildDriverDataset();

        $fuelRanking = $drivers;
        usort($fuelRanking, fn($a, $b) => $b['km_l'] <=> $a['km_l']);

        $safetyEvents = [];

        $totalPresent   = count($drivers);
        $activeDrivers  = count(array_filter($drivers, fn($d) => strtolower((string)($d['status'] ?? '')) === 'active'));

        $this->jsonResponse([
            'success'        => true,
            'drivers'        => $drivers,
            'fuel_ranking'   => array_map(fn($d) => ['name' => $d['name'], 'km_l' => $d['km_l'], 'risk' => $d['risk']], $fuelRanking),
            'safety_events'  => $safetyEvents,
            'attendance'     => [
                'present_rate'  => $totalPresent > 0 ? round(($activeDrivers / $totalPresent) * 100, 1) : 0,
                'absent_rate'   => 0.0,
                'on_leave_rate' => 0.0,
            ],
        ]);
    }

    /**
     * GET /api/driver/reports?type=daily|weekly|monthly|comparison&format=json
     */
    public function getDriverReports(): void
    {
        $this->authorizeRole(['Dispatcher', 'Logistics Officer', 'Admin', 'User']);
        $type   = $_GET['type']   ?? 'monthly';
        $format = $_GET['format'] ?? 'json';
        $drivers = $this->buildDriverDataset();

        $report = [
            'generated_at' => date('Y-m-d H:i:s'),
            'type'         => $type,
            'period'       => $type === 'daily' ? date('Y-m-d') : ($type === 'weekly' ? date('Y-\WW') : date('Y-m')),
            'summary'      => [
                'total_drivers'  => count($drivers),
                'avg_score'      => count($drivers) > 0 ? round(array_sum(array_column($drivers, 'score')) / count($drivers), 1) : 0,
                'total_trips'    => array_sum(array_column($drivers, 'trips')),
                'fleet_km_l'     => count($drivers) > 0 ? round(array_sum(array_column($drivers, 'km_l')) / count($drivers), 2) : 0,
            ],
            'drivers' => $drivers,
        ];

        $this->jsonResponse([
            'success' => true,
            'report'  => $report,
        ]);
    }
}

