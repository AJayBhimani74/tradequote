<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function vendorUser(): User
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs($vendor->user);

        return $vendor->user;
    }

    private function productData(array $overrides = []): array
    {
        return array_merge(['sku' => 'BLT-001', 'name' => 'Hex bolt M8', 'price' => '12.50', 'stock' => 100], $overrides);
    }

    public function test_vendor_can_create_a_product(): void
    {
        $user = $this->vendorUser();

        $this->postJson('/api/products', $this->productData())
            ->assertCreated()->assertJsonPath('data.sku', 'BLT-001')->assertJsonPath('data.price', '12.50');

        $this->assertSame(1, $user->vendor->products()->count());
    }

    public function test_buyer_cannot_create_a_product(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Buyer]));

        $this->postJson('/api/products', $this->productData())->assertForbidden();
    }

    public function test_sku_must_be_unique_per_vendor_only(): void
    {
        Product::factory()->create(['sku' => 'BLT-001']);
        $user = $this->vendorUser();

        $this->postJson('/api/products', $this->productData())->assertCreated();
        $this->postJson('/api/products', $this->productData())
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->assertSame(1, $user->vendor->products()->count());
    }

    public function test_vendor_cannot_edit_or_delete_another_vendors_product(): void
    {
        $other = Product::factory()->create();
        $this->vendorUser();

        $this->putJson("/api/products/{$other->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->deleteJson("/api/products/{$other->id}")->assertForbidden();
        $this->assertNotSame('Hijacked', $other->fresh()->name);
    }

    public function test_vendor_can_update_and_delete_own_product(): void
    {
        $user = $this->vendorUser();
        $product = Product::factory()->create(['vendor_id' => $user->vendor->id]);

        $this->putJson("/api/products/{$product->id}", ['price' => 99, 'sku' => $product->sku])
            ->assertOk()->assertJsonPath('data.price', '99.00');
        $this->deleteJson("/api/products/{$product->id}")->assertNoContent();
        $this->assertModelMissing($product);
    }

    public function test_buyer_sees_only_active_products_and_can_search(): void
    {
        Product::factory()->create(['name' => 'Copper wire', 'sku' => 'CW-1']);
        Product::factory()->create(['name' => 'Copper pipe', 'sku' => 'CP-1', 'is_active' => false]);
        Product::factory()->create(['name' => 'Steel rod', 'sku' => 'SR-1']);
        Sanctum::actingAs(User::factory()->create(['role' => Role::Buyer]));

        $this->getJson('/api/products')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/products?q=copper')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'CW-1');
    }

    public function test_inactive_product_is_hidden_from_buyers_but_visible_to_owner(): void
    {
        $product = Product::factory()->create(['is_active' => false]);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Buyer]));
        $this->getJson("/api/products/{$product->id}")->assertNotFound();

        Sanctum::actingAs($product->vendor->user);
        $this->getJson("/api/products/{$product->id}")->assertOk();
        $this->getJson('/api/my/products')->assertOk()->assertJsonCount(1, 'data');
    }
}
