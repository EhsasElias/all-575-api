<?php

use App\Http\Controllers\Api\V1\SellerWorkspaceController;
use App\Http\Controllers\Api\V1\SellerAccessController;
use App\Http\Controllers\Api\V1\SellerApplicationController;
use App\Http\Controllers\Api\V1\SellerApplicationDocumentController;
use App\Http\Controllers\Api\V1\SellerReviewController;
use App\Http\Controllers\Api\V1\SellerCatalogWorkspaceController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SellerStockAdjustmentController;
use App\Http\Controllers\Api\V1\SellerProductDraftController;
use App\Http\Controllers\Api\V1\SellerProductVariantController;
use App\Http\Controllers\Api\V1\SellerProductImageController;
use App\Http\Controllers\Api\V1\SellerCatalogImportController;
use App\Http\Controllers\Api\V1\SellerStaffController;
use App\Http\Controllers\Api\V1\SellerReturnController;
use App\Http\Controllers\Api\V1\SellerFinanceController;
use App\Http\Controllers\Api\V1\SellerFinanceLedgerController;
use App\Http\Controllers\Api\V1\SellerSettlementReadinessController;
use App\Http\Controllers\Api\V1\SellerCommissionRateController;
use App\Http\Controllers\Api\V1\SellerCommissionPreviewController;
use App\Http\Controllers\Api\V1\SellerSettlementPreviewController;
use App\Http\Controllers\Api\V1\SellerRefundAllocationController;
use App\Http\Controllers\Api\V1\SellerBranchProfileController;
use App\Http\Controllers\Api\V1\SellerBranchOnboardingController;
use App\Http\Controllers\Api\V1\SellerStoreProfileController;

Route::middleware(['auth:sanctum', 'abilities:customer', 'active', 'throttle:30,1'])->group(function (): void {
    Route::get('seller/application', [SellerApplicationController::class, 'show']);
    Route::put('seller/application', [SellerApplicationController::class, 'save']);
    Route::post('seller/application/submit', [SellerApplicationController::class, 'submit']);
    Route::get('seller/application/documents', [SellerApplicationDocumentController::class, 'index']);
    Route::post('seller/application/documents', [SellerApplicationDocumentController::class, 'upload'])->middleware('throttle:10,1');
});

// Review rights require a separately provisioned active seller_review_grants row.
// Customer authentication alone never grants access; controller checks every request.
Route::middleware(['auth:sanctum', 'abilities:customer', 'active', 'throttle:20,1'])
    ->prefix('seller-review')->group(function (): void {
        Route::get('applications', [SellerReviewController::class, 'applications']);
        Route::get('applications/{application}', [SellerReviewController::class, 'application'])->whereNumber('application');
        Route::get('applications/{application}/documents/{document}',
            [SellerReviewController::class, 'document'])->whereNumber('application')->whereNumber('document');
        Route::post('applications/{application}/decision',
            [SellerReviewController::class, 'decideApplication'])->whereNumber('application')->middleware('throttle:10,1');
        Route::get('branches', [SellerReviewController::class, 'branches']);
        Route::post('branches/{branch}/decision', [SellerReviewController::class, 'decideBranch'])->whereNumber('branch')->middleware('throttle:10,1');
        Route::get('products', [SellerReviewController::class, 'products']);
        Route::post('products/{product}/decision',
            [SellerReviewController::class, 'decideProduct'])->whereNumber('product')->middleware('throttle:10,1');
    });

Route::get('seller/memberships', [SellerAccessController::class, 'memberships'])
    ->middleware(['auth:sanctum', 'abilities:customer', 'active']);

Route::post('seller/session', [SellerAccessController::class, 'issue'])
    ->middleware(['auth:sanctum', 'abilities:customer', 'active', 'throttle:10,1']);

Route::middleware(['auth:sanctum', 'abilities:seller', 'active'])
    ->prefix('seller')->group(function (): void {
        Route::get('stores/{seller}', [SellerStoreProfileController::class, 'show'])->whereNumber('seller');
        Route::patch('stores/{seller}', [SellerStoreProfileController::class, 'update'])->whereNumber('seller')->middleware('throttle:20,1');
        Route::get('branches', [SellerCatalogWorkspaceController::class, 'branches']);
        Route::get('service-zones', [SellerBranchOnboardingController::class, 'serviceZones']);
        Route::post('branches', [SellerBranchOnboardingController::class, 'create'])->middleware('throttle:10,1');
        Route::patch('branches/{branch}', [SellerBranchProfileController::class, 'update'])->whereNumber('branch')->middleware('throttle:20,1');
        Route::get('staff', [SellerStaffController::class, 'index']);
        Route::get('returns', [SellerReturnController::class, 'index']);
        Route::get('returns/{case}', [SellerReturnController::class, 'show'])->whereUlid('case');
        Route::post('returns/{case}/response', [SellerReturnController::class, 'respond'])->whereUlid('case')->middleware('throttle:10,1');
        Route::patch('staff/{membership}/deactivate', [SellerStaffController::class, 'deactivate'])->whereNumber('membership')->middleware('throttle:10,1');
        Route::get('products', [SellerCatalogWorkspaceController::class, 'products']);
        Route::get('products/import/template.csv', function (): \Symfony\Component\HttpFoundation\Response {
            $header = "category_id,name_ar,name_en,price_halalas,vat_class,sku,barcode\r\n";
            return response("\xEF\xBB\xBF".$header, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="seller-products-template.csv"',
            ]);
        })->middleware('throttle:30,1');
        Route::post('products/import', SellerCatalogImportController::class)->middleware('throttle:5,1');
        Route::post('products', [SellerProductDraftController::class, 'store'])->middleware('throttle:30,1');
        Route::patch('products/{product}', [SellerProductDraftController::class, 'update'])->whereNumber('product');
        Route::post('products/{product}/submit', [SellerProductDraftController::class, 'submit'])->whereNumber('product');
        Route::post('products/{product}/variants', [SellerProductVariantController::class, 'store'])->whereNumber('product')->middleware('throttle:30,1');
        Route::post('products/{product}/image', SellerProductImageController::class)->whereNumber('product')->middleware('throttle:10,1');
        Route::get('stock', [SellerCatalogWorkspaceController::class, 'stock']);
        Route::patch('stock/{stock}', SellerStockAdjustmentController::class)->whereNumber('stock')->middleware('throttle:30,1');
        Route::get('dashboard', [SellerWorkspaceController::class, 'dashboard']);
        Route::get('orders', [SellerWorkspaceController::class, 'orders']);
        Route::get('reports/sales', [SellerWorkspaceController::class, 'salesSummary'])->middleware('throttle:30,1');
        Route::get('finance/summary', [SellerFinanceController::class, 'summary'])->middleware('throttle:20,1');
        Route::get('finance/ledger', SellerFinanceLedgerController::class)->middleware('throttle:20,1');
        Route::get('finance/settlement-readiness', SellerSettlementReadinessController::class)->middleware('throttle:20,1');
        Route::get('finance/commission-rates', SellerCommissionRateController::class)->middleware('throttle:20,1');
        Route::get('finance/commission-preview', SellerCommissionPreviewController::class)->middleware('throttle:10,1');
        Route::get('finance/settlement-preview', SellerSettlementPreviewController::class)->middleware('throttle:10,1');
        Route::get('finance/refund-allocations', SellerRefundAllocationController::class)->middleware('throttle:20,1');
        Route::get('orders/{suborder}/packing-list', [SellerWorkspaceController::class, 'packingList'])->whereUlid('suborder');
        Route::get('orders/export.csv', [SellerWorkspaceController::class, 'exportOrders'])->middleware('throttle:10,1');
        Route::get('orders/export.xlsx', [SellerWorkspaceController::class, 'exportOrdersExcel'])->middleware('throttle:10,1');
        Route::get('orders/{suborder}', [SellerWorkspaceController::class, 'showOrder'])->whereUlid('suborder');
        Route::patch('orders/{suborder}/status', [SellerWorkspaceController::class, 'advance']);
    });
