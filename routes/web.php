<?php

use App\Http\Controllers\ClipperController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ClipperController::class, 'index'])->name('clipper.index');
Route::post('/process', [ClipperController::class, 'store'])->name('clip.store');
Route::get('/clips/{clip}/stream', [ClipperController::class, 'stream'])->name('clips.stream');
Route::get('/clips/{clip}/download', [ClipperController::class, 'download'])->name('clips.download');
Route::delete('/clips/{clip}', [ClipperController::class, 'destroy'])->name('clips.destroy');
