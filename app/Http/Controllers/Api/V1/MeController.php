<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Actions\RecordConsents;
use App\Domain\Identity\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateMarketingConsentRequest;
use App\Http\Requests\Api\V1\UpdateMeRequest;
use App\Http\Resources\UserResource;

class MeController extends Controller
{
    public function show(): UserResource
    {
        return new UserResource(request()->user());
    }

    public function update(UpdateMeRequest $request): UserResource
    {
        $user = $request->user();
        $user->update($request->validated());

        return new UserResource($user->refresh());
    }

    public function updateMarketingConsent(
        UpdateMarketingConsentRequest $request,
        RecordConsents $recordConsents,
        AuditLogger $audit,
    ): UserResource {
        $user = $request->user();
        $granted = $request->boolean('granted');

        $recordConsents->handle($user, ['marketing' => $granted], $user->locale, $request->ip());
        $audit->record('consent.marketing', $user->id, $user, ['granted' => $granted]);

        return new UserResource($user->refresh());
    }
}
