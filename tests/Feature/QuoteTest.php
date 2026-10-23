<?php

namespace Tests\Feature;

use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\QuoteRequested;
use App\Notifications\QuoteStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->vendor = Vendor::factory()->create();
        $this->buyer = User::factory()->create(['role' => Role::Buyer]);
    }

    private function quoteWithItems(QuoteStatus $status = QuoteStatus::Requested, array $attrs = []): Quote
    {
        $quote = Quote::factory()->create(array_merge([
            'buyer_id' => $this->buyer->id,
            'vendor_id' => $this->vendor->id,
            'status' => $status,
        ], $attrs));
        $product = Product::factory()->create(['vendor_id' => $this->vendor->id]);
        $quote->items()->create([
            'product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
            'unit' => 'pcs', 'quantity' => 10,
        ]);

        return $quote;
    }

    private function respondPayload(Quote $quote, array $overrides = []): array
    {
        return array_merge([
            'valid_until' => now()->addWeek()->toDateString(),
            'vendor_note' => 'Delivery in 5 days',
            'items' => $quote->items->map(fn ($i) => ['id' => $i->id, 'unit_price' => 2.5])->all(),
        ], $overrides);
    }

    public function test_buyer_requests_a_quote_and_vendor_is_notified(): void
    {
        $products = Product::factory()->count(2)->create(['vendor_id' => $this->vendor->id]);
        Sanctum::actingAs($this->buyer);

        $this->postJson('/api/quotes', [
            'vendor_id' => $this->vendor->id,
            'buyer_note' => 'Need by month end',
            'items' => [
                ['product_id' => $products[0]->id, 'quantity' => 10],
                ['product_id' => $products[1]->id, 'quantity' => 5],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonCount(2, 'data.items');

        Notification::assertSentTo($this->vendor->user, QuoteRequested::class);
    }

    public function test_items_must_belong_to_the_selected_active_vendor_products(): void
    {
        $foreign = Product::factory()->create();
        $inactive = Product::factory()->create(['vendor_id' => $this->vendor->id, 'is_active' => false]);
        Sanctum::actingAs($this->buyer);

        foreach ([$foreign, $inactive] as $product) {
            $this->postJson('/api/quotes', [
                'vendor_id' => $this->vendor->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertUnprocessable()->assertJsonValidationErrors('items');
        }
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_vendor_cannot_request_quotes(): void
    {
        Sanctum::actingAs($this->vendor->user);

        $this->postJson('/api/quotes', [])->assertForbidden();
    }

    public function test_vendor_responds_with_prices_and_total_is_calculated(): void
    {
        $quote = $this->quoteWithItems();
        Sanctum::actingAs($this->vendor->user);

        $this->postJson("/api/quotes/{$quote->id}/respond", $this->respondPayload($quote))
            ->assertOk()
            ->assertJsonPath('data.status', 'quoted')
            ->assertJsonPath('data.total', '25.00')
            ->assertJsonPath('data.items.0.unit_price', '2.50');

        Notification::assertSentTo($this->buyer, QuoteStatusChanged::class);
    }

    public function test_response_must_price_every_item(): void
    {
        $quote = $this->quoteWithItems();
        Sanctum::actingAs($this->vendor->user);

        $this->postJson("/api/quotes/{$quote->id}/respond", $this->respondPayload($quote, [
            'items' => [['id' => 99999, 'unit_price' => 1]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(QuoteStatus::Requested, $quote->fresh()->status);
    }

    public function test_other_vendor_cannot_respond(): void
    {
        $quote = $this->quoteWithItems();
        Sanctum::actingAs(Vendor::factory()->create()->user);

        $this->postJson("/api/quotes/{$quote->id}/respond", $this->respondPayload($quote))->assertForbidden();
    }

    public function test_buyer_accepts_a_quoted_quote(): void
    {
        $quote = $this->quoteWithItems(QuoteStatus::Quoted, ['valid_until' => now()->addDay()]);
        Sanctum::actingAs($this->buyer);

        $this->postJson("/api/quotes/{$quote->id}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        Notification::assertSentTo($this->vendor->user, QuoteStatusChanged::class);
    }

    public function test_expired_quote_cannot_be_accepted(): void
    {
        $quote = $this->quoteWithItems(QuoteStatus::Quoted, ['valid_until' => now()->subDay()]);
        Sanctum::actingAs($this->buyer);

        $this->postJson("/api/quotes/{$quote->id}/accept")->assertStatus(409);
        $this->assertSame(QuoteStatus::Quoted, $quote->fresh()->status);
    }

    public function test_invalid_transitions_return_conflict(): void
    {
        $requested = $this->quoteWithItems();
        $accepted = $this->quoteWithItems(QuoteStatus::Accepted);

        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/quotes/{$requested->id}/accept")->assertStatus(409);
        $this->postJson("/api/quotes/{$accepted->id}/cancel")->assertStatus(409);

        Sanctum::actingAs($this->vendor->user);
        $this->postJson("/api/quotes/{$accepted->id}/respond", $this->respondPayload($accepted))->assertStatus(409);
    }

    public function test_buyer_can_cancel_and_reject(): void
    {
        $open = $this->quoteWithItems();
        $quoted = $this->quoteWithItems(QuoteStatus::Quoted);
        Sanctum::actingAs($this->buyer);

        $this->postJson("/api/quotes/{$open->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/quotes/{$quoted->id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_vendor_can_decline_a_request(): void
    {
        $quote = $this->quoteWithItems();
        Sanctum::actingAs($this->vendor->user);

        $this->postJson("/api/quotes/{$quote->id}/decline")->assertOk()->assertJsonPath('data.status', 'declined');
    }

    public function test_listing_is_scoped_to_the_current_user_and_filterable(): void
    {
        $this->quoteWithItems();
        $this->quoteWithItems(QuoteStatus::Quoted);
        Quote::factory()->create();

        Sanctum::actingAs($this->buyer);
        $this->getJson('/api/quotes')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/quotes?status=quoted')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/quotes?status=bogus')->assertUnprocessable();

        Sanctum::actingAs($this->vendor->user);
        $this->getJson('/api/quotes')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_strangers_cannot_view_a_quote(): void
    {
        $quote = $this->quoteWithItems();

        Sanctum::actingAs(User::factory()->create(['role' => Role::Buyer]));
        $this->getJson("/api/quotes/{$quote->id}")->assertForbidden();

        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/quotes/{$quote->id}")->assertOk();
    }
}
