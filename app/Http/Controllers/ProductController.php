<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::with('vendor')
            ->where('is_active', true)
            ->when($request->integer('vendor_id'), fn ($q, $id) => $q->where('vendor_id', $id))
            ->search($request->query('q'))
            ->orderBy('name')
            ->paginate(20);

        return ProductResource::collection($products);
    }

    public function mine(Request $request): AnonymousResourceCollection
    {
        $products = $request->user()->vendor->products()
            ->search($request->query('q'))
            ->orderBy('name')
            ->paginate(20);

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        $product = $request->user()->vendor->products()->create($request->validated());

        return new ProductResource($product);
    }

    public function show(Product $product): ProductResource
    {
        abort_unless($product->is_active || auth()->user()->vendor?->id === $product->vendor_id, 404);

        return new ProductResource($product->load('vendor'));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $this->authorize('update', $product);

        $product->update($request->validated());

        return new ProductResource($product);
    }

    public function destroy(Product $product): Response
    {
        $this->authorize('delete', $product);

        $product->delete();

        return response()->noContent();
    }
}
