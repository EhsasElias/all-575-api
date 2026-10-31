<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Actions\LoginWithFirebase;
use App\Domain\Identity\Services\FirebaseIdTokenVerifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FirebaseLoginRequest;
use App\Http\Resources\UserResource;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Http\JsonResponse;

class FirebaseAuthController extends Controller
{
    public function login(FirebaseLoginRequest $request, FirebaseIdTokenVerifier $verifier, LoginWithFirebase $login): JsonResponse
    {
        if (! config('services.firebase.enabled')) {
            throw new DomainRuleViolation('firebase_unavailable', 'Firebase authentication is not enabled.', 503);
        }

        $identity = $verifier->verify($request->string('id_token')->toString());
        $result = $login->handle(
            $identity,
            $request->string('device_name')->toString() ?: 'unknown device',
            app()->getLocale(),
            $request->consents(),
            $request->ip(),
        );

        return response()->json(['data' => [
            'token' => $result->token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $result->token->accessToken->expires_at,
            'is_new_user' => $result->isNewUser,
            'user' => new UserResource($result->user),
        ]], $result->isNewUser ? 201 : 200);
    }
}
