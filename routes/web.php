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
Route::delete('/produse/{produs}', [ProdusController::class, 'destroy'])->name('produse.destroy');
Route::get('/facturi-furnizori', [FacturaFurnizorImportController::class, 'index'])->name('facturi-furnizori.index');
Route::post('/facturi-furnizori/incarcare', [FacturaFurnizorImportController::class, 'upload'])->name('facturi-furnizori.upload');
Route::get('/facturi-furnizori/previzualizare', [FacturaFurnizorImportController::class, 'preview'])->name('facturi-furnizori.preview');
Route::get('/facturi-furnizori/previzualizare/produs-nou/{line}', [FacturaFurnizorImportController::class, 'newProduct'])
    ->whereNumber('line')
    ->name('facturi-furnizori.produs-nou');
Route::post('/facturi-furnizori/previzualizare/produs-nou/{line}', [FacturaFurnizorImportController::class, 'storeNewProduct'])
    ->whereNumber('line')
    ->name('facturi-furnizori.produs-nou.store');
Route::patch('/facturi-furnizori/previzualizare/pret/{line}', [FacturaFurnizorImportController::class, 'confirmPrice'])
    ->whereNumber('line')
    ->name('facturi-furnizori.pret.confirmare');
Route::post('/facturi-furnizori/import', [FacturaFurnizorImportController::class, 'store'])->name('facturi-furnizori.store');
Route::post('/facturi-furnizori/anulare', [FacturaFurnizorImportController::class, 'cancel'])->name('facturi-furnizori.cancel');
Route::get('/facturi-furnizori/{factura}/linii/{linie}/produs-nou', [FacturaFurnizorImportController::class, 'newProductFromImported'])
    ->name('facturi-furnizori.importat.produs-nou');
Route::post('/facturi-furnizori/{factura}/linii/{linie}/produs-nou', [FacturaFurnizorImportController::class, 'storeNewProductFromImported'])
    ->name('facturi-furnizori.importat.produs-nou.store');
Route::get('/facturi-furnizori/{factura}', [FacturaFurnizorImportController::class, 'show'])->name('facturi-furnizori.show');
Route::patch('/facturi-furnizori/{factura}/mapari', [FacturaFurnizorImportController::class, 'updateMappings'])->name('facturi-furnizori.mapari');
Route::post('/facturi-furnizori/{factura}/finalizare', [FacturaFurnizorImportController::class, 'finalizeImport'])->name('facturi-furnizori.finalizare');
Route::delete('/facturi-furnizori/{factura}', [FacturaFurnizorImportController::class, 'destroy'])->name('facturi-furnizori.destroy');
