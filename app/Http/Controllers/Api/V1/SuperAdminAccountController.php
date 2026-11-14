<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SuperAdminAccountController extends Controller
{
    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()?->is_active && DB::table('platform_super_admin_grants')
            ->where('user_id', $request->user()->id)->where('is_active', true)->exists(), 403);
    }

    public function update(Request $request, int $user): JsonResponse
    {
        $this->authorizeOwner($request);
        $input = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        abort_if($input === [], 422, 'No account changes provided.');
        abort_if(array_key_exists('is_active', $input)
            && ! $input['is_active'] && $user === (int) $request->user()->id,
            422, 'You cannot deactivate your own account.');

        $result = DB::transaction(function () use ($user, $input): object {
            $account = DB::table('users')->where('id', $user)->lockForUpdate()->first();
            abort_unless($account !== null, 404);
            // Do not allow disabling another Super Admin here; grant management is separate.
            if (array_key_exists('is_active', $input) && ! $input['is_active']) {
                abort_if(DB::table('platform_super_admin_grants')
                    ->where('user_id', $user)->where('is_active', true)->exists(),
                    422, 'Revoke Super Admin access through the dedicated permission workflow first.');
            }
            DB::table('users')->where('id', $user)->update($input + ['updated_at' => now()]);
            if (array_key_exists('is_active', $input) && ! $input['is_active']) {
                DB::table('personal_access_tokens')->where('tokenable_type',
                    \App\Domain\Identity\Models\User::class)->where('tokenable_id', $user)->delete();
            }
            return DB::table('users')->where('id', $user)
                ->first(['id', 'name', 'email', 'is_active', 'created_at']);
        }, 3);

        return response()->json(['data' => $result], 200);
    }
    public function updateSeller(Request $request, int $seller): JsonResponse
    {
        $this->authorizeOwner($request);
        $input = $request->validate([
            'name_ar' => ['sometimes', 'required', 'string', 'max:160'],
            'name_en' => ['sometimes', 'required', 'string', 'max:160'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        abort_if($input === [], 422, 'No seller changes provided.');

        $result = DB::transaction(function () use ($seller, $input, $request): object {
            $existing = DB::table('sellers')->where('id', $seller)->lockForUpdate()->first();
            abort_unless($existing !== null, 404);
            $updates = array_diff_key($input, ['is_active' => true]);
            if (array_key_exists('is_active', $input)) {
                abort_unless(in_array($existing->status, ['approved', 'suspended'], true),
                    422, 'Pending sellers must use the application approval process.');
                $updates['status'] = $input['is_active'] ? 'approved' : 'suspended';
            }
            $updates['updated_at'] = now();
            DB::table('sellers')->where('id', $seller)->update($updates);
            \Illuminate\Support\Facades\Log::info('platform.super_admin.seller_updated', [
                'actor_user_id' => $request->user()->id,
                'seller_id' => $seller,
                'previous_status' => $existing->status,
                'new_status' => $updates['status'] ?? $existing->status,
                'updated_fields' => array_keys($input),
            ]);
            return DB::table('sellers')->where('id', $seller)
                ->first(['id', 'name_ar', 'name_en', 'status', 'created_at']);
        }, 3);

        return response()->json(['data' => $result]);
    }

}
