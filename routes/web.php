<?php

use App\Http\Controllers\ClipperController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ClipperController::class, 'index'])->name('clipper.index');
Route::post('/process', [ClipperController::class, 'store'])->name('clip.store');
