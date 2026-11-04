<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * Private seller onboarding documents. No public URLs and no approval decisions.
 * Uploads only for editable applications; all ownership checks use authenticated user.
 */
final class SellerApplicationDocumentController extends Controller
{
    private const TYPES = ['commercial_registration', 'vat_certificate', 'national_address', 'iban_certificate'];

    public function index(Request $request): JsonResponse
    {
        $application = DB::table('seller_applications')->where('user_id', $request->user()->id)->first();
        if (! $application) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => DB::table('seller_application_documents')
            ->where('application_id', $application->id)
            ->select('id', 'document_type', 'original_name', 'mime_type', 'size_bytes', 'created_at', 'updated_at')
            ->orderBy('document_type')
            ->get()]);
    }

    public function upload(Request $request): JsonResponse
    {
        abort_unless($request->user()->isVerified(), 403);
        $data = $request->validate([
            'document_type' => ['required', Rule::in(self::TYPES)],
            'file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max('10mb')],
        ]);

        $application = DB::table('seller_applications')->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless(in_array($application->status, ['draft', 'correction'], true), 409, 'Submitted documents cannot be changed.');

        $file = $data['file'];
        $path = $file->store('seller-applications/'.$application->id, 'local');
        if ($path === false) {
            abort(500, 'Unable to store document.');
        }

        try {
            $oldPath = DB::transaction(function () use ($request, $application, $data, $file, $path): ?string {
                $locked = DB::table('seller_applications')
                    ->where('id', $application->id)
                    ->where('user_id', $request->user()->id)
                    ->lockForUpdate()->firstOrFail();
                abort_unless(in_array($locked->status, ['draft', 'correction'], true), 409,
                    'Application status changed. Refresh and retry.');

                $existing = DB::table('seller_application_documents')
                    ->where('application_id', $locked->id)
                    ->where('document_type', $data['document_type'])
                    ->first();

                $attributes = [
                    'file_path' => $path,
                    'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                    'updated_at' => now(),
                ];

                if ($existing) {
                    DB::table('seller_application_documents')->where('id', $existing->id)->update($attributes);
                } else {
                    DB::table('seller_application_documents')->insert($attributes + [
                        'application_id' => $locked->id,
                        'document_type' => $data['document_type'],
                        'created_at' => now(),
                    ]);
                }

                return $existing?->file_path;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        return response()->json(['data' => [
            'document_type' => $data['document_type'],
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'size_bytes' => $file->getSize(),
        ]], 201);
    }
}
