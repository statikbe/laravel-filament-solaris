<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class SeedCategory extends Model
{
    protected $table = 'seed_categories';

    protected $fillable = ['name', 'slug', 'description', 'weight', 'is_active'];

    protected $casts = [
        'weight' => 'integer',
        'is_active' => 'boolean',
    ];
}
