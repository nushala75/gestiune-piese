<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacturaFurnizorImportController;
use App\Http\Controllers\ProdusController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/produse', [ProdusController::class, 'index'])->name('produse.index');
Route::patch('/produse/{produs}/editare-rapida', [ProdusController::class, 'updateRapid'])->name('produse.update-rapid');
Route::get('/produse/{produs}/detalii', [ProdusController::class, 'editDetalii'])->name('produse.edit-detalii');
Route::patch('/produse/{produs}/detalii', [ProdusController::class, 'updateDetalii'])->name('produse.update-detalii');
Route::get('/facturi-furnizori', [FacturaFurnizorImportController::class, 'index'])->name('facturi-furnizori.index');
Route::post('/facturi-furnizori/incarcare', [FacturaFurnizorImportController::class, 'upload'])->name('facturi-furnizori.upload');
Route::get('/facturi-furnizori/previzualizare', [FacturaFurnizorImportController::class, 'preview'])->name('facturi-furnizori.preview');
Route::post('/facturi-furnizori/import', [FacturaFurnizorImportController::class, 'store'])->name('facturi-furnizori.store');
Route::post('/facturi-furnizori/anulare', [FacturaFurnizorImportController::class, 'cancel'])->name('facturi-furnizori.cancel');
