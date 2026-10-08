<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proposal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['anomalies' => 'array', 'edit_history' => 'array', 'divergence' => 'boolean', 'claimed' => 'boolean', 'decided_at' => 'datetime'];
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organisation::class, 'organization_id');
    }

    public function import()
    {
        return $this->belongsTo(ImportBatch::class, 'import_id');
    }
}
