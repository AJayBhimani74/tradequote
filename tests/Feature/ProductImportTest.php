<?php

namespace Tests\Feature;

use App\Jobs\ImportProductsJob;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->vendor = Vendor::factory()->create();
        Sanctum::actingAs($this->vendor->user);
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.csv', $content);
    }

    public function test_upload_is_queued_and_returns_accepted(): void
    {
        Queue::fake();

        $this->postJson('/api/products/import', ['file' => $this->csv("sku,name,price\nA1,Bolt,1.5\n")])
            ->assertStatus(202)->assertJsonPath('status', 'pending');

        Queue::assertPushed(ImportProductsJob::class);
        $this->assertDatabaseCount('product_imports', 1);
    }

    public function test_upload_requires_a_csv_file(): void
    {
        $this->postJson('/api/products/import', [])->assertUnprocessable();
        $this->postJson('/api/products/import', ['file' => UploadedFile::fake()->image('a.png')])
            ->assertUnprocessable();
    }

    public function test_valid_rows_are_imported_and_bad_rows_reported_with_line_numbers(): void
    {
        $csv = "sku,name,description,unit,price,stock\n"
            ."A1,Hex bolt,Zinc plated,box,12.50,40\n"
            ."A2,,Missing name,pcs,5,1\n"
            ."A3,Washer,,,abc,10\n"
            ."\n"
            ."A4,Nut,,,0.75,\n";

        $id = $this->postJson('/api/products/import', ['file' => $this->csv($csv)])->json('id');

        $this->getJson("/api/product-imports/$id")->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('total_rows', 4)
            ->assertJsonPath('imported_rows', 2)
            ->assertJsonPath('failed_rows', 2)
            ->assertJsonPath('errors.0.row', 3)
            ->assertJsonPath('errors.1.row', 4);

        $this->assertSame(2, $this->vendor->products()->count());
        $this->assertSame('pcs', $this->vendor->products()->where('sku', 'A4')->value('unit'));
    }

    public function test_reimport_updates_existing_sku_instead_of_duplicating(): void
    {
        Product::factory()->create(['vendor_id' => $this->vendor->id, 'sku' => 'A1', 'price' => 1]);

        $this->postJson('/api/products/import', ['file' => $this->csv("sku,name,price\nA1,Updated bolt,9.99\n")]);

        $this->assertSame(1, $this->vendor->products()->count());
        $this->assertSame('9.99', $this->vendor->products()->first()->price);
    }

    public function test_same_sku_from_another_vendor_is_untouched(): void
    {
        $other = Product::factory()->create(['sku' => 'A1', 'name' => 'Theirs']);

        $this->postJson('/api/products/import', ['file' => $this->csv("sku,name,price\nA1,Mine,2\n")]);

        $this->assertSame('Theirs', $other->fresh()->name);
        $this->assertSame(1, $this->vendor->products()->count());
    }

    public function test_missing_required_columns_fails_the_import(): void
    {
        $id = $this->postJson('/api/products/import', ['file' => $this->csv("code,title\nA1,Bolt\n")])->json('id');

        $this->getJson("/api/product-imports/$id")
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('errors.0.row', 1);
        $this->assertSame(0, $this->vendor->products()->count());
    }

    public function test_vendor_cannot_see_another_vendors_import(): void
    {
        $import = ProductImport::create(['vendor_id' => Vendor::factory()->create()->id, 'path' => 'imports/x.csv']);

        $this->getJson("/api/product-imports/{$import->id}")->assertNotFound();
    }
}
