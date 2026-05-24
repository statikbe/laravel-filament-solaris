<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Lightweight in-memory model for exercising `$record` injection into action
 * closures. Never persisted or queried — instantiated with attributes and read
 * directly — so it needs no migration.
 */
class ClosureTestRecord extends Model
{
    protected $table = 'closure_test_records';

    protected $guarded = [];

    public $timestamps = false;
}
