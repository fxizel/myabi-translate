<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $guarded = [];

    // Retained for existing database schemas only; imports no longer have modes.
    protected $attributes = ['is_complete' => false];

    protected function casts(): array
    {
        return ['report' => 'array', 'is_complete' => 'boolean'];
    }

    public function version()
    {
        return $this->belongsTo(MyabiVersion::class, 'version_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organisation::class, 'organization_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function originalPath(): string
    {
        return storage_path('app/private/'.$this->path);
    }
}
