<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** The signed-in account's own in-app notifications; anyone else's is not found. */
class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    /** Newest first, with the unread count the bell shows. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = $user->notifications()->reorder()->latest()->latest('id')->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (DatabaseNotification $notification) => self::entry($notification, $user))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $found = $request->user()->notifications()->findOrFail($notification);
        $found->markAsRead();

        return response()->json(['data' => self::entry($found, $request->user())]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['meta' => ['unread_count' => 0]]);
    }

    /** The link opens the Complaint under the recipient's own section of the SPA. */
    private static function entry(DatabaseNotification $notification, User $user): array
    {
        $section = match (true) {
            $user->hasRole(Role::Resident) => 'resident',
            $user->hasRole(Role::Manager) => 'manager',
            $user->hasRole(Role::Technician) => 'technician',
            default => 'admin',
        };

        return [
            'id' => $notification->id,
            'event' => $notification->type,
            'message' => $notification->data['message'],
            'complaint' => ['id' => $notification->data['complaint_id'], 'reference_no' => $notification->data['reference_no']],
            'link' => "/{$section}/complaints/{$notification->data['complaint_id']}",
            'read_at' => $notification->read_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $notification->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
