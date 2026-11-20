<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function markRead(Request $request, $alert): JsonResponse|RedirectResponse
    {
        $alertId = is_object($alert) ? (string)$alert->id : (string)$alert;

        // Synchronize with Centralized Backend Service FIRST
        $res = $this->notificationService->markNotificationRead($alertId);

        if (!$res && !$this->notificationService->getClient()->wasSuccessful()) {
            $errorMsg = $this->notificationService->getClient()->getLastError()
                ?? 'Failed to mark notification read on centralized backend.';
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => $errorMsg], $this->notificationService->getClient()->getLastStatusCode() ?: 500);
            }
            return back()->withErrors(['error' => $errorMsg]);
        }

        try {
            if ($alert instanceof Alert && $alert->read_at === null) {
                $alert->read_at = now();
                $alert->save();
            }
        } catch (\Throwable) {}

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        // Synchronize with Centralized Backend Service FIRST
        $res = $this->notificationService->markAllNotificationsRead();

        if (!$res && !$this->notificationService->getClient()->wasSuccessful()) {
            $errorMsg = $this->notificationService->getClient()->getLastError()
                ?? 'Failed to mark all notifications read on centralized backend.';
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => $errorMsg], $this->notificationService->getClient()->getLastStatusCode() ?: 500);
            }
            return back()->withErrors(['error' => $errorMsg]);
        }

        try {
            Alert::whereNull('read_at')->update(['read_at' => now()]);
        } catch (\Throwable) {}

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('status', 'All notifications marked as read.');
    }
}
