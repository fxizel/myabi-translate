<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organisation extends Model
{
    protected $table = 'organizations';

    protected $fillable = ['name', 'code', 'is_root', 'active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_root' => 'boolean', 'active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'organization_id');
    }
}
