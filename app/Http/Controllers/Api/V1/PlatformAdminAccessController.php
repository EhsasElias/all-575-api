<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Domain\Notifications\RoleNotificationWriter;
use Illuminate\Validation\Rule;

final class PlatformAdminAccessController extends Controller
{
    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()->is_active && DB::table('platform_super_admin_grants')
            ->where('user_id', $request->user()->id)->where('is_active', true)->exists(), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        return response()->json(['data' => DB::table('platform_admin_access as access')
            ->join('users', 'users.id', '=', 'access.user_id')
            ->join('sellers', 'sellers.id', '=', 'access.seller_id')
            ->select('access.id', 'access.user_id', 'users.name', 'users.email',
                'access.seller_id', 'sellers.name_ar as seller_name_ar',
                'sellers.name_en as seller_name_en', 'access.is_active',
                'access.can_view_orders', 'access.can_view_inventory',
                'access.can_review_sellers')
            ->orderByDesc('access.id')->limit(100)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);
        $input = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'seller_id' => ['required', 'integer', 'exists:sellers,id'],
            'can_view_orders' => ['required', 'boolean'],
            'can_view_inventory' => ['required', 'boolean'],
        ]);

        $user = DB::table('users')->where('email', $input['email'])
            ->where('is_active', true)->whereNotNull('email_verified_at')->first(['id']);
        abort_unless($user !== null, 422, 'A verified and active existing account is required.');

        abort_if(DB::table('platform_super_admin_grants')->where('user_id', $user->id)
            ->where('is_active', true)->exists(), 422, 'Super Admin access cannot be changed using Admin grants.');

        $record = DB::transaction(function () use ($request, $input, $user): object {
            $existing = DB::table('platform_admin_access')
                ->where('user_id', $user->id)->where('seller_id', $input['seller_id'])
                ->lockForUpdate()->first();
            abort_if($existing !== null, 409, 'This account is already assigned to this seller.');

            $id = DB::table('platform_admin_access')->insertGetId([
                'user_id' => $user->id,
                'seller_id' => $input['seller_id'],
                'is_active' => true,
                'can_view_orders' => $input['can_view_orders'],
                'can_view_inventory' => $input['can_view_inventory'],
                'can_review_sellers' => false,
                'granted_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return DB::table('platform_admin_access')->where('id', $id)->first();
        }, 3);

        app(RoleNotificationWriter::class)->send((int) $user->id, 'admin_assigned',
            'تم تعيين صلاحية الإدارة', 'تم منحك صلاحية إدارة متجر. افتح لوحة الإدارة لعرض التفاصيل.',
            '/account/admin');

        return response()->json(['data' => $record], 201);
    }

    public function update(Request $request, int $access): JsonResponse
    {
        $this->authorizeOwner($request);
        $input = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'can_view_orders' => ['sometimes', 'boolean'],
            'can_view_inventory' => ['sometimes', 'boolean'],
        ]);
        abort_if($input === [], 422, 'No changes provided.');
        $existing = DB::table('platform_admin_access')->where('id', $access)->first();
        abort_unless($existing !== null, 404);

        DB::table('platform_admin_access')->where('id', $access)->update($input + ['updated_at' => now()]);
        if (array_key_exists('is_active', $input) && (bool) $input['is_active'] !== (bool) $existing->is_active) {
            app(RoleNotificationWriter::class)->send((int) $existing->user_id,
                $input['is_active'] ? 'admin_reactivated' : 'admin_revoked',
                $input['is_active'] ? 'تم تفعيل صلاحية الإدارة' : 'تم إيقاف صلاحية الإدارة',
                $input['is_active'] ? 'يمكنك الآن الوصول إلى المتجر المسند إليك.' : 'تم إيقاف وصولك إلى المتجر المسند إليك.',
                '/account');
        }
        return response()->json(['data' => DB::table('platform_admin_access')->where('id', $access)->first()]);
    }
}
