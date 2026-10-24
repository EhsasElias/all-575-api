<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Actions\RequestOtp;
use App\Domain\Identity\Actions\VerifyOtp;
use App\Domain\Identity\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestOtpRequest;
use App\Http\Requests\Api\V1\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    /**
     * Send a one-time code. The answer is the same whether or not the number already has an account.
     *
     * @status 202
     *
     * @response array{data: array{challenge_id: string, expires_in: int, resend_after: int}}
     */
    public function requestOtp(RequestOtpRequest $request, RequestOtp $requestOtp): JsonResponse
    {
        $challenge = $requestOtp->handle(
            $request->channel(),
            (string) $request->normalizedDestination(),
            app()->getLocale(),
            $request->ip(),
        );

        return response()->json(['data' => [
            'challenge_id' => $challenge->id,
            'expires_in' => (int) config('store.otp.ttl_seconds'),
            'resend_after' => (int) config('store.otp.resend_cooldown_seconds'),
        ]], 202);
    }

    /**
     * Check the code and sign in. A new account must also send the current Terms and Privacy versions.
     *
     * @response array{data: array{token: string, token_type: string, expires_at: string, is_new_user: bool, user: UserResource}}
     */
    public function verifyOtp(VerifyOtpRequest $request, VerifyOtp $verifyOtp): JsonResponse
    {
        $login = $verifyOtp->handle(
            $request->string('challenge_id')->toString(),
            $request->string('code')->toString(),
            $request->string('device_name')->toString() ?: 'unknown device',
            app()->getLocale(),
            $request->consents(),
            $request->ip(),
        );

        return response()->json(['data' => [
            'token' => $login->token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $login->token->accessToken->expires_at,
            'is_new_user' => $login->isNewUser,
            'user' => new UserResource($login->user),
        ]], $login->isNewUser ? 201 : 200);
    }

    public function logout(Request $request, AuditLogger $audit): Response
    {
        $request->user()->currentAccessToken()->delete();
        $audit->record('auth.logout', $request->user()->id, $request->user());

        return response()->noContent();
    }
}
