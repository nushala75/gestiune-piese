<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacturaFurnizorImportController;
use App\Http\Controllers\FurnizorController;
use App\Http\Controllers\ProdusController;
use App\Http\Controllers\ReceptieController;
use App\Http\Controllers\SagaExportController;
use App\Http\Controllers\StocCsvImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/configurare-administrator', [AuthController::class, 'showSetup'])->name('admin.setup');
    Route::post('/configurare-administrator', [AuthController::class, 'setup'])->middleware('throttle:5,1')->name('admin.setup.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/produse', [ProdusController::class, 'index'])->name('produse.index');
    Route::get('/produse/adauga', [ProdusController::class, 'create'])->name('produse.create');
    Route::post('/produse', [ProdusController::class, 'store'])->name('produse.store');
    Route::post('/produse/actualizare-stoc', [StocCsvImportController::class, 'store'])->name('produse.actualizare-stoc');
    Route::patch('/produse/{produs}/editare-rapida', [ProdusController::class, 'updateRapid'])->name('produse.update-rapid');
    Route::get('/produse/{produs}/detalii', [ProdusController::class, 'editDetalii'])->name('produse.edit-detalii');
    Route::patch('/produse/{produs}/detalii', [ProdusController::class, 'updateDetalii'])->name('produse.update-detalii');
    Route::delete('/produse/{produs}', [ProdusController::class, 'destroy'])->name('produse.destroy');
    Route::get('/furnizori', [FurnizorController::class, 'index'])->name('furnizori.index');
    Route::get('/furnizori/adauga', [FurnizorController::class, 'create'])->name('furnizori.create');
    Route::post('/furnizori', [FurnizorController::class, 'store'])->name('furnizori.store');
    Route::get('/furnizori/{furnizor}/editare', [FurnizorController::class, 'edit'])->name('furnizori.edit');
    Route::patch('/furnizori/{furnizor}', [FurnizorController::class, 'update'])->name('furnizori.update');
    Route::get('/facturi-furnizori', [FacturaFurnizorImportController::class, 'index'])->name('facturi-furnizori.index');
    Route::post('/facturi-furnizori/incarcare', [FacturaFurnizorImportController::class, 'upload'])->name('facturi-furnizori.upload');
    Route::post('/facturi-furnizori/storno/incarcare', [FacturaFurnizorImportController::class, 'uploadStorno'])->name('facturi-furnizori.storno.upload');
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
    Route::get('/facturi-furnizori/{factura}/receptie', [ReceptieController::class, 'create'])->name('receptii.create');
    Route::post('/facturi-furnizori/{factura}/receptie', [ReceptieController::class, 'store'])->name('receptii.store');
    Route::post('/facturi-furnizori/{factura}/export-saga', [SagaExportController::class, 'generate'])->name('facturi-furnizori.export-saga');
    Route::get('/facturi-furnizori/{factura}', [FacturaFurnizorImportController::class, 'show'])->name('facturi-furnizori.show');
    Route::patch('/facturi-furnizori/{factura}/mapari', [FacturaFurnizorImportController::class, 'updateMappings'])->name('facturi-furnizori.mapari');
    Route::post('/facturi-furnizori/{factura}/finalizare', [FacturaFurnizorImportController::class, 'finalizeImport'])->name('facturi-furnizori.finalizare');
    Route::delete('/facturi-furnizori/{factura}', [FacturaFurnizorImportController::class, 'destroy'])->name('facturi-furnizori.destroy');
});
