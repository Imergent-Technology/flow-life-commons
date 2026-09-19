<?php

declare(strict_types=1);

use App\Modules\Health\Http\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->name('api.v1.health');
