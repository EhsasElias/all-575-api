<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Category;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SellerCategoryManagementController extends Controller
{
    private function sellerAccess(Request $request, int $sellerId): void
    {
        abort_unless(DB::table('sellers')->where('id', $sellerId)->where('status', 'approved')
            ->whereExists(function ($query) use ($request): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'sellers.id')
                    ->where('seller_staff_access.user_id', $request->user()->id)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager']);
            })->exists(), 403);
    }

    private function reviewer(Request $request): void
    {
        abort_unless($request->user()?->is_active && DB::table('seller_review_grants')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)->exists(), 403);
    }

    private function validParent(?int $parentId, ?int $selfId = null): void
    {
        if ($parentId === null) return;
        abort_if($selfId !== null && $parentId === $selfId, 422, 'A category cannot be its own parent.');
        $parent = Category::query()->where('is_active', true)->findOrFail($parentId);
        abort_unless($parent->parent_id === null, 422, 'Only top-level categories can contain subcategories.');
        if ($selfId !== null) {
            abort_unless(!Category::query()->where('parent_id', $selfId)->exists(), 422,
                'A parent category with children cannot become a subcategory.');
        }
    }

    private function uniqueNames(string $ar, string $en, ?int $parentId, ?int $exceptId = null): void
    {
        $existing = Category::query()->where('parent_id', $parentId)
            ->where(function ($query) use ($ar, $en): void {
                $query->where('name_ar', $ar)->orWhere('name_en', $en);
            })->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))->exists();
        if ($existing) {
            throw ValidationException::withMessages(['name_ar' => ['A category with this name already exists under the selected parent.']]);
        }
    }

    public function sellerRequests(Request $request): JsonResponse
    {
        $sellerId = (int) $request->validate(['seller_id' => ['required', 'integer']])['seller_id'];
        $this->sellerAccess($request, $sellerId);

        return response()->json(['data' => DB::table('seller_category_requests')
            ->where('seller_id', $sellerId)->orderByDesc('id')->limit(100)->get()]);
    }

    public function requestCategory(Request $request): JsonResponse
    {
        $input = $request->validate([
            'seller_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'name_ar' => ['required', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $sellerId = (int) $input['seller_id'];
        $this->sellerAccess($request, $sellerId);
        $parentId = isset($input['parent_id']) ? (int) $input['parent_id'] : null;
        $this->validParent($parentId);
        $ar = trim($input['name_ar']);
        $en = trim($input['name_en']);
        $this->uniqueNames($ar, $en, $parentId);
        $duplicate = DB::table('seller_category_requests')->where('seller_id', $sellerId)
            ->where('status', 'pending')->where('parent_id', $parentId)
            ->where('name_ar', $ar)->exists();
        if ($duplicate) throw ValidationException::withMessages(['name_ar' => ['This category has already been requested.']]);
        $id = DB::table('seller_category_requests')->insertGetId([
            'seller_id' => $sellerId, 'requested_by' => $request->user()->id,
            'parent_id' => $parentId, 'name_ar' => $ar, 'name_en' => $en,
            'reason' => $input['reason'] ?? null, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['data' => ['id' => $id, 'status' => 'pending']], 201);
    }

    public function categories(Request $request): JsonResponse
    {
        $this->reviewer($request);
        return response()->json(['data' => Category::query()
            ->orderBy('parent_id')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'parent_id', 'name_ar', 'name_en', 'is_active', 'sort_order'])]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->reviewer($request);
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'name_ar' => ['required', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
            'sort_order' => ['sometimes', 'integer', 'between:0,10000'],
        ]);
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $this->validParent($parentId);
        $this->uniqueNames(trim($data['name_ar']), trim($data['name_en']), $parentId);
        $category = Category::query()->create([
            'parent_id' => $parentId, 'slug' => 'category-'.Str::lower(Str::random(18)),
            'name_ar' => trim($data['name_ar']), 'name_en' => trim($data['name_en']),
            'sort_order' => $data['sort_order'] ?? 0, 'is_active' => true,
        ]);
        return response()->json(['data' => $category], 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        $this->reviewer($request);
        $data = $request->validate([
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')],
            'name_ar' => ['sometimes', 'required', 'string', 'max:180'],
            'name_en' => ['sometimes', 'required', 'string', 'max:180'],
            'sort_order' => ['sometimes', 'integer', 'between:0,10000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        return DB::transaction(function () use ($category, $data): JsonResponse {
            $item = Category::query()->lockForUpdate()->findOrFail($category);
            $parentId = array_key_exists('parent_id', $data)
                ? ($data['parent_id'] === null ? null : (int) $data['parent_id'])
                : $item->parent_id;
            $this->validParent($parentId, $item->id);
            $ar = trim($data['name_ar'] ?? $item->name_ar);
            $en = trim($data['name_en'] ?? $item->name_en);
            $this->uniqueNames($ar, $en, $parentId, $item->id);
            if (($data['is_active'] ?? $item->is_active) === false) {
                abort_if(Category::query()->where('parent_id', $item->id)->where('is_active', true)->exists(),
                    422, 'Deactivate child categories first.');
                abort_if(DB::table('products')->where('category_id', $item->id)->exists(),
                    422, 'Products use this category. Move products before deactivating it.');
            }
            $item->fill(['parent_id' => $parentId, 'name_ar' => $ar,
                'name_en' => $en, 'sort_order' => $data['sort_order'] ?? $item->sort_order,
                'is_active' => $data['is_active'] ?? $item->is_active]);
            $item->save();
            return response()->json(['data' => $item]);
        }, 3);
    }

    public function destroy(Request $request, int $category): JsonResponse
    {
        $this->reviewer($request);
        return DB::transaction(function () use ($category): JsonResponse {
            $item = Category::query()->lockForUpdate()->findOrFail($category);
            abort_if(Category::query()->where('parent_id', $category)->exists(), 422,
                'Delete or move subcategories first.');
            abort_if(DB::table('products')->where('category_id', $category)->exists(), 422,
                'This category is used by products. Move products first.');
            $item->delete();
            return response()->json(['message' => 'Category deleted.']);
        }, 3);
    }

    public function requests(Request $request): JsonResponse
    {
        $this->reviewer($request);
        return response()->json(['data' => DB::table('seller_category_requests')
            ->orderByDesc('id')->limit(100)->get()]);
    }

    public function decide(Request $request, int $categoryRequest): JsonResponse
    {
        $this->reviewer($request);
        $input = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'review_note' => ['nullable', 'string', 'max:1000', 'required_if:decision,reject'],
        ]);
        return DB::transaction(function () use ($request, $categoryRequest, $input): JsonResponse {
            $row = DB::table('seller_category_requests')->where('id', $categoryRequest)
                ->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_unless($row->status === 'pending', 409, 'Request already reviewed.');
            $categoryId = null;
            if ($input['decision'] === 'approve') {
                $parentId = $row->parent_id === null ? null : (int) $row->parent_id;
                $this->validParent($parentId);
                $this->uniqueNames($row->name_ar, $row->name_en, $parentId);
                $categoryId = Category::query()->create([
                    'parent_id' => $parentId, 'slug' => 'category-'.Str::lower(Str::random(18)),
                    'name_ar' => $row->name_ar, 'name_en' => $row->name_en,
                    'sort_order' => 0, 'is_active' => true,
                ])->id;
            }
            DB::table('seller_category_requests')->where('id', $categoryRequest)->update([
                'status' => $input['decision'] === 'approve' ? 'approved' : 'rejected',
                'category_id' => $categoryId,
                'review_note' => $input['review_note'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            return response()->json(['data' => [
                'id' => $categoryRequest,
                'status' => $input['decision'] === 'approve' ? 'approved' : 'rejected',
                'category_id' => $categoryId,
            ]]);
        }, 3);
    }
}
