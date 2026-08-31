<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $limit = min(12, max(1, $request->integer('limit', 3)));
        $isAuthenticated = $this->isAuthenticated($request);

        return response()->json(
            Announcement::visible()
                ->where(fn ($query) => $query
                    ->where('audience', 'all')
                    ->when($isAuthenticated, fn ($query) => $query->orWhere('audience', 'authenticated')))
                ->with('author:id,full_name,avatar')
                ->orderByDesc('is_pinned')
                ->orderByDesc('published_at')
                ->limit($limit)
                ->get()
        );
    }

    public function show(Request $request, string $slug)
    {
        $isAuthenticated = $this->isAuthenticated($request);

        return response()->json(
            Announcement::visible()
                ->with('author:id,full_name,avatar')
                ->where(fn ($query) => $query
                    ->where('audience', 'all')
                    ->when($isAuthenticated, fn ($query) => $query->orWhere('audience', 'authenticated')))
                ->where('slug', $slug)
                ->firstOrFail()
        );
    }

    private function isAuthenticated(Request $request): bool
    {
        if ($request->user() !== null) {
            return true;
        }

        return $request->bearerToken()
            && PersonalAccessToken::findToken($request->bearerToken())?->tokenable !== null;
    }
}
