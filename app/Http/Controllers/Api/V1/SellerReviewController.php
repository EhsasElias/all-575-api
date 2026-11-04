<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SellerStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Seller;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Explicitly granted human reviewer. Seller/customer tokens do not imply review rights.
 * All decisions are atomic, ownership changes are restricted, and decisions are audited.
 */
final class SellerReviewController extends Controller
{
    private function authorizeReviewer(Request $request): void
    {
        abort_unless($request->user()->is_active && DB::table('seller_review_grants')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)->exists(), 403);
    }

    public function applications(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request);
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['submitted', 'correction', 'approved', 'rejected'])],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $rows = DB::table('seller_applications')
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->select('id', 'user_id', 'legal_name', 'commercial_registration', 'contact_name',
                'city', 'status', 'review_note', 'submitted_at', 'approved_seller_id', 'updated_at')
            ->orderByDesc('id')->limit($validated['limit'] ?? 30)->get();

        return response()->json(['data' => $rows]);
    }

    public function application(Request $request, int $application): JsonResponse
    {
        $this->authorizeReviewer($request);

        $item = DB::table('seller_applications')->where('id', $application)->firstOrFail();
        if ($item->iban) {
            try {
                $iban = Crypt::decryptString($item->iban);
                $item->iban = substr($iban, 0, 4).'****************'.substr($iban, -4);
            } catch (DecryptException) {
                $item->iban = null;
            }
        }
        $documents = DB::table('seller_application_documents')->where('application_id', $application)
            ->select('id', 'document_type', 'original_name', 'mime_type', 'size_bytes', 'updated_at')->get();

        return response()->json(['data' => ['application' => $item, 'documents' => $documents]]);
    }

    public function document(Request $request, int $application, int $document): BinaryFileResponse
    {
        $this->authorizeReviewer($request);
        abort_unless(DB::table('seller_applications')->where('id', $application)->exists(), 404);

        $item = DB::table('seller_application_documents')->where('id', $document)
            ->where('application_id', $application)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($item->file_path), 404);

        return response()->download(
            Storage::disk('local')->path($item->file_path),
            'document-'.$item->id.'.'.($item->mime_type === 'application/pdf' ? 'pdf'
                : ($item->mime_type === 'image/png' ? 'png' : 'jpg')),
            ['Cache-Control' => 'private, no-store']
        );
    }

    public function decideApplication(Request $request, int $application): JsonResponse
    {
        $this->authorizeReviewer($request);
        $input = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'correction', 'reject'])],
            'reason' => ['required_unless:decision,approve', 'nullable', 'string', 'min:10', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($request, $application, $input): array {
            $item = DB::table('seller_applications')->where('id', $application)
                ->lockForUpdate()->firstOrFail();
            abort_unless($item->status === 'submitted', 409, 'Only submitted applications can be reviewed.');

            $status = match ($input['decision']) {
                'approve' => 'approved',
                'correction' => 'correction',
                'reject' => 'rejected',
            };
            $sellerId = null;

            if ($status === 'approved') {
                $documents = DB::table('seller_application_documents')
                    ->where('application_id', $application)->pluck('document_type')->all();
                abort_unless(in_array('commercial_registration', $documents, true), 422,
                    'Commercial registration document is required before approval.');
                abort_unless(in_array('national_address', $documents, true), 422,
                    'National address document is required before approval.');
                abort_unless(in_array('iban_certificate', $documents, true), 422,
                    'IBAN certificate is required before approval.');

                // Store identity is generated once, during review, not from customer input.
                $seller = Seller::query()->create([
                    'slug' => 'seller-'.Str::lower(Str::random(20)),
                    'name_ar' => $item->legal_name,
                    'name_en' => $item->legal_name,
                    'status' => SellerStatus::Approved,
                ]);
                $sellerId = $seller->id;

                DB::table('seller_staff_access')->insert([
                    'user_id' => $item->user_id,
                    'seller_id' => $sellerId,
                    'branch_id' => null,
                    'role' => 'owner',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('seller_applications')->where('id', $application)->update([
                'status' => $status,
                'review_note' => $input['reason'] ?? null,
                'approved_seller_id' => $sellerId,
                'updated_at' => now(),
            ]);

            $this->audit((int) $request->user()->id, 'application', $application,
                (string) $item->status, $status, $input['reason'] ?? null);

            return ['id' => $application, 'status' => $status, 'seller_id' => $sellerId];
        }, 3);

        return response()->json(['data' => $result]);
    }

    public function products(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request);
        $validated = $request->validate(['limit' => ['sometimes', 'integer', 'between:1,50']]);

        return response()->json(['data' => DB::table('products')
            ->join('sellers', 'sellers.id', '=', 'products.seller_id')
            ->where('products.status', ProductStatus::PendingReview->value)
            ->where('sellers.status', SellerStatus::Approved->value)
            ->select('products.id', 'products.seller_id', 'products.category_id',
                'products.name_ar', 'products.name_en', 'products.price_halalas',
                'products.image_path', 'products.status', 'products.created_at')
            ->orderBy('products.id')->limit($validated['limit'] ?? 30)->get()]);
    }

    public function decideProduct(Request $request, int $product): JsonResponse
    {
        $this->authorizeReviewer($request);
        $input = $request->validate([
            'decision' => ['required', Rule::in(['publish', 'reject'])],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'min:10', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($request, $product, $input): array {
            $item = Product::query()->lockForUpdate()->findOrFail($product);
            abort_unless($item->status === ProductStatus::PendingReview, 409, 'Only pending products can be reviewed.');
            abort_unless(DB::table('sellers')->where('id', $item->seller_id)
                ->where('status', SellerStatus::Approved->value)->exists(), 409, 'Seller is not approved.');

            $status = $input['decision'] === 'publish' ? ProductStatus::Published : ProductStatus::Rejected;
            if ($status === ProductStatus::Published) {
                abort_unless($item->variants()->where('is_active', true)->exists(), 422, 'Active variant required.');
                abort_unless(DB::table('categories')->where('id', $item->category_id)
                    ->where('is_active', true)->exists(), 422, 'Active category required.');
                abort_unless($item->image_path && Storage::disk('public')->exists($item->image_path),
                    422, 'Uploaded product cover image is required.');
            }

            $item->status = $status;
            $item->save();

            $this->audit((int) $request->user()->id, 'product', $item->id,
                ProductStatus::PendingReview->value, $status->value, $input['reason'] ?? null);

            return ['id' => $item->id, 'status' => $status->value];
        }, 3);

        return response()->json(['data' => $result]);
    }

    public function branches(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request);

        return response()->json(['data' => DB::table('branches')
            ->join('sellers', 'sellers.id', '=', 'branches.seller_id')
            ->where('branches.is_active', false)
            ->where('sellers.status', 'approved')
            ->select('branches.id', 'branches.seller_id', 'branches.service_zone_id',
                'branches.code', 'branches.name_ar', 'branches.name_en',
                'branches.lat', 'branches.lng', 'branches.created_at')
            ->orderByDesc('branches.id')->limit(50)->get()]);
    }

    public function decideBranch(Request $request, int $branch): JsonResponse
    {
        $this->authorizeReviewer($request);
        $input = $request->validate([
            'decision' => ['required', Rule::in(['activate'])],
        ]);

        $result = DB::transaction(function () use ($request, $branch): array {
            $row = DB::table('branches')->where('id', $branch)->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_if($row->is_active, 409, 'Branch already active.');
            abort_unless(DB::table('sellers')->where('id', $row->seller_id)
                ->where('status', 'approved')->exists(), 409, 'Seller approval required.');

            $zone = \App\Domain\Catalog\Models\ServiceZone::query()->active()
                ->findOrFail($row->service_zone_id);
            abort_unless($zone->distanceTo((float) $row->lat, (float) $row->lng) <= $zone->radius_meters,
                422, 'Branch location must belong to the active zone.');

            DB::table('branches')->where('id', $branch)->update(['is_active' => true, 'updated_at' => now()]);
            $this->audit((int) $request->user()->id, 'branch', $branch,
                'inactive', 'active', null);

            return ['id' => $branch, 'is_active' => true];
        }, 3);

        return response()->json(['data' => $result]);
    }

    private function audit(int $reviewerId, string $type, int $id, string $previous,
        string $next, ?string $reason): void
    {
        DB::table('seller_review_audits')->insert([
            'reviewer_id' => $reviewerId,
            'subject_type' => $type,
            'subject_id' => $id,
            'previous_status' => $previous,
            'new_status' => $next,
            'reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
