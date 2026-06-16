<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Statikbe\FilamentSolaris\Http\Controllers\BatchFailureDownloadController;
use Statikbe\FilamentSolaris\Support\Batch\BatchFailureReport;

Route::middleware(['signed', SubstituteBindings::class])
    ->get('filament-solaris/batch-failures/{run}', BatchFailureDownloadController::class)
    ->name(BatchFailureReport::ROUTE);
