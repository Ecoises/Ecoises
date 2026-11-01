<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\EcoNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notificaciones de la campana. Solo expone las de la app (type "eco.*"),
 * nunca los avisos internos del panel que comparten la tabla.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, $request->integer('per_page', 15)));

        $page = $this->query($request)
            ->when($request->query('filter') === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (DatabaseNotification $notification) => $this->present($notification)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'unread_count' => $this->countUnread($request),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->countUnread($request)]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->query($request)->findOrFail($id);
        $notification->markAsRead();

        return response()->json($this->present($notification));
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = $this->query($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->query($request)->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function countUnread(Request $request): int
    {
        return $this->query($request)->whereNull('read_at')->count();
    }

    private function query(Request $request)
    {
        return $request->user()
            ->notifications()
            ->where('type', 'like', EcoNotification::TYPE_PREFIX.'%');
    }

    private function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'type' => substr($notification->type, strlen(EcoNotification::TYPE_PREFIX)),
            'category' => $data['category'] ?? 'general',
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? null,
            'action_url' => $data['action_url'] ?? null,
            'image' => $data['image'] ?? null,
            'meta' => $data['meta'] ?? [],
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
