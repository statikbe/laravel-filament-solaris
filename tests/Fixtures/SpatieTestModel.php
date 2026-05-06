<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class SpatieTestModel extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'spatie_test_models';

    protected $guarded = [];

    public $timestamps = false;
}
