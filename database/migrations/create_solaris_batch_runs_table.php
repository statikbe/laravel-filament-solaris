<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(FilamentSolaris::config()->getBatchRunsTable(), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('action_name')->index();
            $table->string('user_id')->nullable()->index();
            $table->string('page')->nullable()->index();
            $table->string('context')->nullable();
            $table->string('status')->default('processing');
            $table->unsignedInteger('total')->nullable();
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('discarded')->default(0);
            $table->uuid('bus_batch_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'action_name', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(FilamentSolaris::config()->getBatchRunsTable());
    }
};
