<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkOperation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['override' => 'boolean', 'preview' => 'array', 'counts' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function import()
    {
        return $this->belongsTo(ImportBatch::class, 'import_id');
    }
}
