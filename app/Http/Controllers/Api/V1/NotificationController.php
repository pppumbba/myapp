<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in user's own notifications. Nobody sees or changes anyone else's. */
class NotificationController extends Controller
{
    /** GET /notifications?unread=1&page=: newest first, with how many are unread. */
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->userNotifications()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->latest('id')
            ->paginate(20);

        return response()->json($notifications->toArray() + [
            'unread' => $request->user()->userNotifications()->whereNull('read_at')->count(),
        ]);
    }

    /** POST /notifications/{notification}/read */
    public function read(Request $request, UserNotification $notification): JsonResponse
    {
        $this->own($request, $notification);
        $notification->read_at ??= now();
        $notification->save();

        return response()->json($notification);
    }

    /** POST /notifications/read-all */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()->userNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }

    /** DELETE /notifications/{notification} */
    public function destroy(Request $request, UserNotification $notification): JsonResponse
    {
        $this->own($request, $notification);
        $notification->delete();

        return response()->json(null, 204);
    }

    /** Someone else's notification is answered as not found, so ids don't leak. */
    private function own(Request $request, UserNotification $notification): void
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
    }
}
