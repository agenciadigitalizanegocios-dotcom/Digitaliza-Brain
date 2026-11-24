<?php

use App\Http\Controllers\Api\BrainApiController;
use Illuminate\Support\Facades\Route;

// Usado pelo n8n. Header: Authorization: Bearer <token da imobiliária>
// 'internal' recusa o que chega pelo Cloudflare: a API é só para o n8n, por dentro do Docker.
Route::prefix('v1')->middleware(['internal', 'tenant.token', 'throttle:120,1'])->group(function () {
    Route::get('/brain', BrainApiController::class);
});
