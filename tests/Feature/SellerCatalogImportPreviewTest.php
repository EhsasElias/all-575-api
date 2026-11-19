<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use App\Domain\Reporting\Services\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SellerCatalogImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function signInSeller(): Seller
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $user = User::factory()->create();
        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id,
            'user_id' => $user->id,
            'branch_id' => null,
            'role' => 'owner',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($user, ['seller']);

        return $seller;
    }

    private function csv(int $categoryId, string $sku = 'TEST-SKU-001'): UploadedFile
    {
        $content = 'category_id,name_ar,name_en,price_halalas,vat_class,sku,barcode'."\r\n".
            $categoryId.',منتج اختبار,Test Product,575,standard,'.$sku.',123456789'."\r\n";

        return UploadedFile::fake()->createWithContent('products.csv', $content);
    }

    public function test_preview_checks_csv_without_writing_products(): void
    {
        $seller = $this->signInSeller();
        $category = Category::factory()->create();

        $this->post('/api/v1/seller/products/import', [
            'seller_id' => $seller->id,
            'dry_run' => '1',
            'file' => $this->csv($category->id),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.created_drafts', 0)
            ->assertJsonPath('data.preview.0.sku', 'TEST-SKU-001');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_confirmed_import_creates_draft_after_preview(): void
    {
        $seller = $this->signInSeller();
        $category = Category::factory()->create();

        $this->post('/api/v1/seller/products/import', [
            'seller_id' => $seller->id,
            'dry_run' => '1',
            'file' => $this->csv($category->id),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->post('/api/v1/seller/products/import', [
            'seller_id' => $seller->id,
            'file' => $this->csv($category->id),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.created_drafts', 1);

        $this->assertDatabaseHas('products', [
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'status' => 'draft',
        ]);
        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_repeated_preview_never_creates_or_duplicates_products(): void
    {
        $seller = $this->signInSeller();
        $category = Category::factory()->create();

        for ($n = 0; $n < 2; $n++) {
            $this->post('/api/v1/seller/products/import', [
                'seller_id' => $seller->id,
                'dry_run' => '1',
                'file' => $this->csv($category->id),
            ], ['Accept' => 'application/json'])->assertOk();
        }

        $this->assertDatabaseCount('products', 0);
    }

    public function test_current_downloaded_price_header_is_accepted_in_csv_and_excel(): void
    {
        $seller = $this->signInSeller();
        $category = Category::factory()->create();
        $template = $this->get('/api/v1/seller/products/import/template.csv')->assertOk()->getContent();
        $headers = str_getcsv(trim(substr($template, 3)), escape: '\\');
        $row = ['TEST-TEMPLATE-001', '', '', $category->id, 'منتج اختبار', 'Test Product', '', '', '5.75', 'standard', '', '', '', '', ''];
        $xlsx = (new XlsxWriter)->download($headers, [$row], 'products.xlsx')->getFile();
        $uploads = [
            UploadedFile::fake()->createWithContent('products.csv', rtrim($template)."\n".implode(',', $row)."\n"),
            new UploadedFile($xlsx->getPathname(), 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ];

        try {
            foreach ($uploads as $upload) {
                $this->post('/api/v1/seller/products/import', [
                    'seller_id' => $seller->id,
                    'dry_run' => '1',
                    'file' => $upload,
                ], ['Accept' => 'application/json'])
                    ->assertOk()
                    ->assertJsonPath('data.valid_rows', 1)
                    ->assertJsonPath('data.preview.0.price_halalas', 575);
            }
        } finally {
            @unlink($xlsx->getPathname());
        }
        $this->assertDatabaseCount('products', 0);
    }
}
