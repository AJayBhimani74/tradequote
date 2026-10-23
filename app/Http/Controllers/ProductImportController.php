<?php

namespace App\Http\Controllers;

use App\Jobs\ImportProductsJob;
use App\Models\ProductImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductImportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $import = $request->user()->vendor->productImports()->create([
            'path' => $request->file('file')->store('imports'),
            'status' => 'pending',
        ]);

        ImportProductsJob::dispatch($import);

        return response()->json($import->only('id', 'status'), 202);
    }

    public function show(Request $request, ProductImport $import): JsonResponse
    {
        abort_unless($import->vendor_id === $request->user()->vendor->id, 404);

        return response()->json($import->only(
            'id', 'status', 'total_rows', 'imported_rows', 'failed_rows', 'errors', 'finished_at'
        ));
    }
}
