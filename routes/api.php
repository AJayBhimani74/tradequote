<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\QuoteController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

    Route::get('/quotes', [QuoteController::class, 'index']);
    Route::get('/quotes/{quote}', [QuoteController::class, 'show']);

    Route::middleware('role:buyer')->group(function () {
        Route::post('/quotes', [QuoteController::class, 'store']);
        Route::post('/quotes/{quote}/accept', [QuoteController::class, 'accept']);
        Route::post('/quotes/{quote}/reject', [QuoteController::class, 'reject']);
        Route::post('/quotes/{quote}/cancel', [QuoteController::class, 'cancel']);
    });

    Route::middleware('role:vendor')->group(function () {
        Route::post('/quotes/{quote}/respond', [QuoteController::class, 'respond']);
        Route::post('/quotes/{quote}/decline', [QuoteController::class, 'decline']);
        Route::get('/my/products', [ProductController::class, 'mine']);
        Route::post('/products/import', [ProductImportController::class, 'store']);
        Route::get('/product-imports/{import}', [ProductImportController::class, 'show']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    });
});
