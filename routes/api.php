<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\SpecieController;
use Laravel\Socialite\Facades\Socialite;


// Rutas de autenticación
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/auth/google', [GoogleController::class, 'authenticate']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']); 
    Route::post('/logout', [AuthController::class, 'logout']); 

    Route::apiResource('/species', SpecieController::class);
});
   








