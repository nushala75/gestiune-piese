<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProdusController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/produse', [ProdusController::class, 'index'])->name('produse.index');
Route::patch('/produse/{produs}/editare-rapida', [ProdusController::class, 'updateRapid'])->name('produse.update-rapid');
Route::get('/produse/{produs}/detalii', [ProdusController::class, 'editDetalii'])->name('produse.edit-detalii');
Route::patch('/produse/{produs}/detalii', [ProdusController::class, 'updateDetalii'])->name('produse.update-detalii');
