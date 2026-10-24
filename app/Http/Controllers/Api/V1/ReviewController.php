<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Reviews\Actions\SubmitReview;
use App\Domain\Reviews\Models\Review;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReviewRequest;
use App\Http\Requests\Api\V1\UpdateReviewRequest;
use App\Http\Resources\ReviewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    public function index(string $slug): AnonymousResourceCollection
    {
        $product = Product::query()->visible()->where('slug', $slug)->firstOrFail();

        return ReviewResource::collection(
            Review::query()->with('user')->where('product_id', $product->id)->where('status', Review::PUBLISHED)->latest('id')->cursorPaginate(20),
        );
    }

    public function store(StoreReviewRequest $request, string $order, SubmitReview $submit): JsonResponse
    {
        $review = $submit->create(
            $request->user(),
            $request->user()->orders()->findOrFail($order),
            $request->string('product_slug')->toString(),
            $request->integer('rating'),
            $request->input('body'),
        );

        return (new ReviewResource($review->load('user')))->response()->setStatusCode(201);
    }

    public function update(UpdateReviewRequest $request, int $review, SubmitReview $submit): ReviewResource
    {
        $owned = Review::query()->where('user_id', $request->user()->id)->findOrFail($review);

        return new ReviewResource($submit->update(
            $owned,
            $request->has('rating') ? $request->integer('rating') : null,
            $request->input('body'),
            $request->has('body'),
        )->load('user'));
    }
}
