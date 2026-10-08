<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Revision extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['validated' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Immutable revision'));
        static::deleting(fn () => throw new \LogicException('Immutable revision'));
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceImport()
    {
        return $this->belongsTo(ImportBatch::class, 'source_import_id');
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }
}
