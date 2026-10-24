<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SessionResource::collection($request->user()->tokens()->latest('id')->get());
    }

    public function destroy(Request $request, int $session, AuditLogger $audit): Response
    {
        $token = $request->user()->tokens()->findOrFail($session);
        $token->delete();

        $audit->record('auth.session_revoked', $request->user()->id, $request->user(), ['device' => $token->name]);

        return response()->noContent();
    }
}
