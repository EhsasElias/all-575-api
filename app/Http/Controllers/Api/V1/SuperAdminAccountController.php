<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
}
