<?php

namespace App\Http\Controllers;

use App\Enums\QuoteStatus;
use App\Http\Requests\RespondQuoteRequest;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Product;
use App\Models\Quote;
use App\Notifications\QuoteRequested;
use App\Notifications\QuoteStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuoteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', Rule::enum(QuoteStatus::class)]]);

        $user = $request->user();

        $quotes = Quote::with(['buyer', 'vendor'])
            ->when($user->isVendor(), fn ($q) => $q->where('vendor_id', $user->vendor->id))
            ->when($user->isBuyer(), fn ($q) => $q->where('buyer_id', $user->id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20);

        return QuoteResource::collection($quotes);
    }

    public function store(StoreQuoteRequest $request): QuoteResource
    {
        $quote = DB::transaction(function () use ($request) {
            $quote = Quote::create([
                'buyer_id' => $request->user()->id,
                'vendor_id' => $request->integer('vendor_id'),
                'status' => QuoteStatus::Requested,
                'buyer_note' => $request->input('buyer_note'),
            ]);

            $products = Product::findMany(collect($request->input('items'))->pluck('product_id'))->keyBy('id');

            foreach ($request->input('items') as $item) {
                $product = $products[$item['product_id']];
                $quote->items()->create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'quantity' => $item['quantity'],
                ]);
            }

            return $quote;
        });

        $quote->vendor->user->notify(new QuoteRequested($quote));

        return new QuoteResource($quote->load(['buyer', 'vendor', 'items']));
    }

    public function show(Quote $quote): QuoteResource
    {
        $this->authorize('view', $quote);

        return new QuoteResource($quote->load(['buyer', 'vendor', 'items']));
    }

    public function respond(RespondQuoteRequest $request, Quote $quote): QuoteResource
    {
        $this->authorize('respond', $quote);
        $this->requireStatus($quote, QuoteStatus::Requested);

        $prices = collect($request->input('items'))->pluck('unit_price', 'id');

        if ($prices->keys()->sort()->values()->all() !== $quote->items()->pluck('id')->sort()->values()->all()) {
            throw ValidationException::withMessages(['items' => 'Every item of the quote must be priced exactly once.']);
        }

        DB::transaction(function () use ($quote, $prices, $request) {
            $total = 0;

            foreach ($quote->items as $item) {
                $item->update(['unit_price' => $prices[$item->id]]);
                $total += $item->quantity * $prices[$item->id];
            }

            $quote->update([
                'status' => QuoteStatus::Quoted,
                'total' => $total,
                'vendor_note' => $request->input('vendor_note'),
                'valid_until' => $request->input('valid_until'),
                'responded_at' => now(),
            ]);
        });

        return $this->changed($quote);
    }

    public function decline(Quote $quote): QuoteResource
    {
        $this->authorize('respond', $quote);
        $this->requireStatus($quote, QuoteStatus::Requested);

        $quote->update(['status' => QuoteStatus::Declined, 'responded_at' => now()]);

        return $this->changed($quote);
    }

    public function accept(Quote $quote): QuoteResource
    {
        $this->authorize('decide', $quote);
        $this->requireStatus($quote, QuoteStatus::Quoted);

        if ($quote->hasExpired()) {
            abort(409, 'This quote has expired.');
        }

        $quote->update(['status' => QuoteStatus::Accepted]);

        return $this->changed($quote);
    }

    public function reject(Quote $quote): QuoteResource
    {
        $this->authorize('decide', $quote);
        $this->requireStatus($quote, QuoteStatus::Quoted);

        $quote->update(['status' => QuoteStatus::Rejected]);

        return $this->changed($quote);
    }

    public function cancel(Quote $quote): QuoteResource
    {
        $this->authorize('decide', $quote);
        abort_unless($quote->status->isOpen(), 409, "A {$quote->status->value} quote can no longer be cancelled.");

        $quote->update(['status' => QuoteStatus::Cancelled]);

        return $this->changed($quote);
    }

    private function requireStatus(Quote $quote, QuoteStatus $expected): void
    {
        abort_unless(
            $quote->status === $expected,
            409,
            "This action needs a {$expected->value} quote, but it is {$quote->status->value}."
        );
    }

    private function changed(Quote $quote): QuoteResource
    {
        $quote->refresh()->load(['buyer', 'vendor.user', 'items']);

        $recipient = auth()->id() === $quote->buyer_id ? $quote->vendor->user : $quote->buyer;
        $recipient->notify(new QuoteStatusChanged($quote));

        return new QuoteResource($quote);
    }
}
