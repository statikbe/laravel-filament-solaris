<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('filament-solaris.batch_tracking.database.tables.problems', 'solaris_batch_problems'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('batch_run_id')
                ->constrained(config('filament-solaris.batch_tracking.database.tables.runs', 'solaris_batch_runs'))
                ->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('identifier')->nullable();
            $table->text('reason');
            $table->text('description')->nullable();
            $table->json('input')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('filament-solaris.batch_tracking.database.tables.problems', 'solaris_batch_problems'));
    }
};
