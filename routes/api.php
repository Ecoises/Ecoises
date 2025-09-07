<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Api\ObservationController;
use App\Http\Controllers\Api\SpeciesController;
use App\Http\Controllers\Api\TaxonController;


// Rutas de autenticación
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/google', [GoogleController::class, 'authenticate']);

Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('reset-password', [AuthController::class, 'resetPassword']);


// Rutas públicas
Route::prefix('species')->group(function () {
    Route::get('/search', [SpeciesController::class, 'search']); // Buscar especies
    Route::get('/{id}', [SpeciesController::class, 'show']); // Detalles de una especie
    Route::post('/suggest', [SpeciesController::class, 'suggestIdentification']); // Sugerir identificación
});

Route::prefix('observations')->group(function () {
    Route::get('/', [ObservationController::class, 'index']); // Listar observaciones con filtros
    Route::get('/public', [ObservationController::class, 'publicObservations']); // Observaciones públicas
});

// Rutas para la gestión de taxones
Route::prefix('taxa')->group(function () {
    Route::get('/search', [TaxonController::class, 'search']); // Buscar taxones
    Route::get('/{id}', [TaxonController::class, 'show']); // Obtener un taxón por ID
    Route::get('/{taxon}/observations', [TaxonController::class, 'observations']); // Obtener observaciones de un taxón
    Route::post('/{taxon}/sync-observations', [TaxonController::class, 'syncObservations']); // Sincronizar observaciones de un taxón
});

// Rutas protegidas (requieren autenticación)
Route::middleware('auth:sanctum')->group(function () {
    // Perfil y logout
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Observaciones
    Route::prefix('observations')->group(function () {
        Route::get('/my', [ObservationController::class, 'myObservations']); // Observaciones del usuario autenticado
        Route::post('/', [ObservationController::class, 'store']); // Crear observación
        Route::get('/{id}', [ObservationController::class, 'show']); // Detalles de una observación
        Route::put('/{id}', [ObservationController::class, 'update']); // Actualizar observación
        Route::delete('/{id}', [ObservationController::class, 'destroy']); // Eliminar observación
    });
});