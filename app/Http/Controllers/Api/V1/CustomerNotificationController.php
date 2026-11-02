<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $query = DB::table('customer_notifications')->where('user_id', $userId);
        $limit = min(max((int) $request->query('limit', 20), 1), 50);
        $beforeId = filter_var($request->query('before_id'), FILTER_VALIDATE_INT);

        $page = (clone $query);
        if ($beforeId !== false && $beforeId > 0) {
            $page->where('id', '<', $beforeId);
        }

        return response()->json([
            'data' => $page->orderByDesc('id')->limit($limit)->get([
                'id', 'type', 'title', 'message', 'target_path', 'read_at', 'created_at',
            ]),
            'unread_count' => DB::table('customer_notifications')
                ->where('user_id', $userId)->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, int $notification): JsonResponse
    {
        $updated = DB::table('customer_notifications')
            ->where('user_id', $request->user()->id)
            ->where('id', $notification)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        $exists = DB::table('customer_notifications')
            ->where('user_id', $request->user()->id)->where('id', $notification)->exists();

        if (! $exists) {
            return response()->json(['message' => 'Notification not found'], 404);
        }

        return response()->json(['data' => ['read' => true, 'updated' => $updated > 0]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        DB::table('customer_notifications')->where('user_id', $request->user()->id)
            ->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['read' => true]]);
    }
}
