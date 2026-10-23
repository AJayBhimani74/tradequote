<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ImportProductsJob implements ShouldQueue
{
    use Queueable;

    private const MAX_STORED_ERRORS = 100;

    private const HEADERS = ['sku', 'name', 'description', 'unit', 'price', 'stock'];

    public function __construct(public ProductImport $import) {}

    public function handle(): void
    {
        $this->import->update(['status' => 'processing']);

        $handle = fopen(Storage::path($this->import->path), 'r');
        $header = array_map(fn ($h) => strtolower(trim($h)), fgetcsv($handle) ?: []);

        if (array_diff(['sku', 'name', 'price'], $header)) {
            fclose($handle);
            $this->import->update([
                'status' => 'failed',
                'errors' => [['row' => 1, 'messages' => ['The header must contain sku, name and price columns.']]],
                'finished_at' => now(),
            ]);

            return;
        }

        $total = $imported = $failed = 0;
        $errors = [];
        $line = 1;

        while (($cells = fgetcsv($handle)) !== false) {
            $line++;

            if ($cells === [null]) {
                continue;
            }

            $total++;
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null))
                + array_fill_keys(self::HEADERS, null);

            $validator = Validator::make($row, [
                'sku' => ['required', 'string', 'max:64'],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'unit' => ['nullable', 'string', 'max:20'],
                'price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
                'stock' => ['nullable', 'integer', 'min:0'],
            ]);

            if ($validator->fails()) {
                $failed++;
                if (count($errors) < self::MAX_STORED_ERRORS) {
                    $errors[] = ['row' => $line, 'messages' => $validator->errors()->all()];
                }

                continue;
            }

            Product::updateOrCreate(
                ['vendor_id' => $this->import->vendor_id, 'sku' => trim($row['sku'])],
                [
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'unit' => $row['unit'] ?: 'pcs',
                    'price' => $row['price'],
                    'stock' => $row['stock'] ?: 0,
                ]
            );
            $imported++;
        }

        fclose($handle);

        $this->import->update([
            'status' => 'completed',
            'total_rows' => $total,
            'imported_rows' => $imported,
            'failed_rows' => $failed,
            'errors' => $errors ?: null,
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->import->update([
            'status' => 'failed',
            'errors' => [['row' => null, 'messages' => [$e->getMessage()]]],
            'finished_at' => now(),
        ]);
    }
}
