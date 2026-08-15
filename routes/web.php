<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProdusController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/produse', [ProdusController::class, 'index'])->name('produse.index');
