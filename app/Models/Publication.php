<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Publication extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['types' => 'array', 'recipients' => 'array', 'manifest' => 'array'];
    }

    public function version()
    {
        return $this->belongsTo(MyabiVersion::class, 'version_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
