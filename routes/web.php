<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\StructureController;
use App\Http\Controllers\Admin\TemplateController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BrainController;
use App\Http\Controllers\BrainItemController;
use App\Http\Controllers\BrainVersionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/cerebro');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// Painel do cliente: cada imobiliária só vê o próprio cérebro.
Route::middleware(['auth', 'tenant', 'readonly.agents'])->prefix('cerebro')->name('brain.')->group(function () {
    Route::get('/', [BrainController::class, 'index'])->name('index');

    Route::get('/versoes', [BrainVersionController::class, 'index'])->name('versions.index');
    Route::get('/versoes/{version}', [BrainVersionController::class, 'show'])->whereNumber('version')->name('versions.show');
    Route::post('/versoes/{version}/restaurar', [BrainVersionController::class, 'restore'])->whereNumber('version')->name('versions.restore');

    Route::get('/{tab}', [BrainController::class, 'show'])->name('tab');
    Route::put('/{tab}', [BrainController::class, 'updateSettings'])->name('settings.update');

    Route::post('/{tab}/itens', [BrainItemController::class, 'store'])->name('items.store');
    Route::put('/{tab}/itens/{item}', [BrainItemController::class, 'update'])->whereNumber('item')->name('items.update');
    Route::patch('/{tab}/itens/{item}/ativo', [BrainItemController::class, 'toggle'])->whereNumber('item')->name('items.toggle');
    Route::patch('/{tab}/itens/{item}/mover/{direction}', [BrainItemController::class, 'move'])
        ->whereNumber('item')->whereIn('direction', ['subir', 'descer'])->name('items.move');
    Route::delete('/{tab}/itens/{item}', [BrainItemController::class, 'destroy'])->whereNumber('item')->name('items.destroy');
});

// Painel admin da Digitaliza.
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/clientes', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('/clientes', [TenantController::class, 'store'])->name('tenants.store');
    Route::post('/clientes/{tenant}/entrar', [TenantController::class, 'enter'])->name('tenants.enter');
    Route::post('/clientes/{tenant}/token', [TenantController::class, 'token'])->name('tenants.token');
    Route::patch('/clientes/{tenant}/status', [TenantController::class, 'status'])->name('tenants.status');
    Route::patch('/clientes/{tenant}/modulos', [TenantController::class, 'modules'])->name('tenants.modules');
    Route::get('/clientes/{tenant}/atualizacao', [TenantController::class, 'update'])->name('tenants.update');
    Route::post('/clientes/{tenant}/atualizacao', [TenantController::class, 'applyUpdate'])->name('tenants.update.apply');
    Route::post('/clientes/{tenant}/atualizacao/dispensar', [TenantController::class, 'dismissUpdate'])->name('tenants.update.dismiss');
    Route::post('/sair-do-cliente', [TenantController::class, 'leave'])->name('tenants.leave');
    Route::get('/registro', AuditController::class)->name('audit');

    // Modelos de nicho.
    Route::get('/modelos', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('/modelos/{template}/duplicar', [TemplateController::class, 'duplicate'])->name('templates.duplicate');
    Route::patch('/modelos/{template}', [TemplateController::class, 'rename'])->name('templates.rename');
    Route::delete('/modelos/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');

    // Editor de estrutura: o mesmo para modelo ({kind} = modelos) e para cliente ({kind} = clientes).
    Route::prefix('/{kind}/{id}/estrutura')->where(['kind' => 'modelos|clientes', 'id' => '[0-9]+'])
        ->name('structure.')->controller(StructureController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::post('/abas', 'storeTab')->name('tabs.store');
            Route::put('/abas/{tab}', 'updateTab')->name('tabs.update');
            Route::patch('/abas/{tab}/arquivar', 'archiveTab')->name('tabs.archive');
            Route::patch('/abas/{tab}/mover/{direction}', 'moveTab')->whereIn('direction', ['subir', 'descer'])->name('tabs.move');
            Route::post('/abas/{tab}/campos', 'storeField')->name('fields.store');
            Route::put('/abas/{tab}/campos/{field}', 'updateField')->name('fields.update');
            Route::patch('/abas/{tab}/campos/{field}/arquivar', 'archiveField')->name('fields.archive');
            Route::patch('/abas/{tab}/campos/{field}/mover/{direction}', 'moveField')->whereIn('direction', ['subir', 'descer'])->name('fields.move');
            Route::put('/fechamento', 'updateClosing')->name('closing');
        });
    Route::post('/modelos/{id}/itens-iniciais/{tab}', [StructureController::class, 'storeStarter'])->whereNumber('id')->name('structure.starter.store');
    Route::delete('/modelos/{id}/itens-iniciais/{index}', [StructureController::class, 'destroyStarter'])->whereNumber('id')->whereNumber('index')->name('structure.starter.destroy');
});
