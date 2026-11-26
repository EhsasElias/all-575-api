<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use App\Support\SellerImportTemplateLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

final class SellerImportExportFlowTest extends TestCase
{
    use RefreshDatabase;

    private function sellerAccess(): array
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $branch = Branch::factory()->for($seller)->create(['is_active' => true]);
        $category = Category::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        DB::table('seller_staff_access')->insert([
            'seller_id' => $seller->id,
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'role' => 'branch_manager',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($user, ['seller']);

        return [$seller, $branch, $category];
    }

    private function csv(string $lang, int $categoryId, int $branchId, string $quantity): UploadedFile
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, SellerImportTemplateLabels::headers($lang), ',', '"', '');
        $vat = SellerImportTemplateLabels::vatOptions($lang)[0];
        fputcsv($out, [
            'FLOW-SKU-1', '', '', (string) $categoryId, 'منتج تجريبي', 'Test product',
            '', '', '5.75', $vat, '', '', '', '', '', (string) $branchId, $quantity,
        ], ',', '"', '');
        rewind($out);
        $contents = stream_get_contents($out);
        fclose($out);

        return UploadedFile::fake()->createWithContent('products.csv', $contents);
    }

    public function test_csv_template_headers_follow_language(): void
    {
        $this->sellerAccess();

        foreach (['ar', 'en', 'fr'] as $lang) {
            $response = $this->get('/api/v1/seller/products/import/template.csv?lang='.$lang)->assertOk();
            $contents = $response->getContent();
            $this->assertStringContainsString(SellerImportTemplateLabels::headers($lang)[0], $contents);
            $this->assertStringContainsString('seller-products-template-'.$lang.'.csv', $response->headers->get('content-disposition'));
        }
    }

    public function test_excel_template_headers_follow_language(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension unavailable');
        }

        $this->sellerAccess();

        foreach (['ar', 'en', 'fr'] as $lang) {
            $response = $this->get('/api/v1/seller/products/import/template.xlsx?lang='.$lang)->assertOk();
            $file = $response->baseResponse->getFile()->getPathname();
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($file) === true);
            try {
                $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
                $this->assertIsString($sheet);
                $this->assertStringContainsString(SellerImportTemplateLabels::headers($lang)[0], $sheet);
                $this->assertStringContainsString('sqref="Q2:Q201"', $sheet);
            } finally {
                $zip->close();
                @unlink($file);
            }
        }
    }

    public function test_import_preview_does_not_save_and_import_saves_stock(): void
    {
        [$seller, $branch, $category] = $this->sellerAccess();

        $this->post('/api/v1/seller/products/import', [
            'seller_id' => $seller->id,
            'dry_run' => '1',
            'file' => $this->csv('fr', $category->id, $branch->id, '7'),
        ])->assertOk()
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.preview.0.inventory_quantity', 7);

        $this->assertDatabaseMissing('product_variants', ['sku' => 'FLOW-SKU-1']);

        $this->post('/api/v1/seller/products/import', [
            'seller_id' => $seller->id,
            'file' => $this->csv('fr', $category->id, $branch->id, '7'),
        ])->assertCreated()->assertJsonPath('data.stock_records_created', 1)
            ->assertJsonPath('data.created_drafts', 1);

        $variantId = DB::table('product_variants')->where('sku', 'FLOW-SKU-1')->value('id');
        $this->assertNotNull($variantId);
        $this->assertDatabaseHas('branch_stock', [
            'branch_id' => $branch->id,
            'variant_id' => $variantId,
            'on_hand' => 7,
            'reserved' => 0,
        ]);
    }

    public function test_downloaded_excel_template_can_be_uploaded_with_inventory(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension unavailable');
        }

        [$seller, $branch, $category] = $this->sellerAccess();
        $response = $this->get('/api/v1/seller/products/import/template.xlsx?lang=en')->assertOk();
        $source = $response->baseResponse->getFile()->getPathname();
        $temp = tempnam(sys_get_temp_dir(), 'import-qa-');
        $this->assertNotFalse($temp);
        try {
            $this->assertTrue(copy($source, $temp));
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($temp) === true);
            try {
                $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
                $this->assertIsString($sheet);
                $values = [
                    'EXCEL-SKU-1', '', '', (string) $category->id,
                    'منتج تجريبي', 'Excel product', '', '', '5.75',
                    SellerImportTemplateLabels::vatOptions('en')[0],
                    '', '', '', '', '', (string) $branch->id, '12',
                ];
                $cells = '';
                foreach ($values as $index => $value) {
                    $column = chr(65 + $index);
                    $cells .= '<c r="'.$column.'2" t="inlineStr"><is><t>'.
                        htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').
                        '</t></is></c>';
                }
                $this->assertStringContainsString('<row r="2">', $sheet);
                $sheet = str_replace('<row r="2">', '<row r="2">'.$cells, $sheet);
                $this->assertTrue($zip->addFromString('xl/worksheets/sheet1.xml', $sheet));
            } finally {
                $zip->close();
            }

            $file = new UploadedFile(
                $temp, 'products.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null, true,
            );
            $this->post('/api/v1/seller/products/import', [
                'seller_id' => $seller->id,
                'file' => $file,
            ])->assertCreated()->assertJsonPath('data.stock_records_created', 1);

            $variantId = DB::table('product_variants')->where('sku', 'EXCEL-SKU-1')->value('id');
            $this->assertNotNull($variantId);
            $this->assertDatabaseHas('branch_stock', [
                'branch_id' => $branch->id,
                'variant_id' => $variantId,
                'on_hand' => 12,
                'reserved' => 0,
            ]);
        } finally {
            @unlink($temp);
            @unlink($source);
        }
    }

    public function test_invalid_quantity_does_not_create_product_or_stock(): void
    {
        [$seller, $branch, $category] = $this->sellerAccess();

        $this->post('/api/v1/seller/products/import', [
            'seller_id' => $seller->id,
            'file' => $this->csv('en', $category->id, $branch->id, '-2'),
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('product_variants', ['sku' => 'FLOW-SKU-1']);
        $this->assertDatabaseCount('branch_stock', 0);
    }

    public function test_import_rejects_stock_in_another_sellers_branch(): void
    {
        [$seller, $branch, $category] = $this->sellerAccess();
        $otherSeller = Seller::factory()->create(['status' => 'approved']);
        $otherBranch = Branch::factory()->for($otherSeller)->create(['is_active' => true]);

        $this->post('/api/v1/seller/products/import', [
            'seller_id' => $seller->id,
            'file' => $this->csv('ar', $category->id, $otherBranch->id, '5'),
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('product_variants', ['sku' => 'FLOW-SKU-1']);
        $this->assertDatabaseCount('branch_stock', 0);
    }
}
