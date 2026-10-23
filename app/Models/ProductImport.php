<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImport extends Model
{
    protected $fillable = [
        'vendor_id', 'path', 'status', 'total_rows', 'imported_rows', 'failed_rows', 'errors', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
