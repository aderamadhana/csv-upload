<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UploadCsv;
Route::get('/', [UploadCsv::class, 'index'])->name('upload.index');
Route::get('/upload', [UploadCsv::class, 'index'])->name('upload.index');
Route::post('/upload', [UploadCsv::class, 'store'])->name('upload.store');
Route::get('/upload/background-status', [UploadCsv::class, 'backgroundStatus'])->name('upload.background');
