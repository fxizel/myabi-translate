<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MyabiVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['released_at' => 'date'];
    }
}
