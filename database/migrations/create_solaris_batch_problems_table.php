<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(FilamentSolaris::config()->getBatchProblemsTable(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('batch_run_id')
                ->constrained(FilamentSolaris::config()->getBatchRunsTable())
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
        Schema::dropIfExists(FilamentSolaris::config()->getBatchProblemsTable());
    }
};
