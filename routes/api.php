<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Api\TaxonController;
use App\Http\Controllers\Api\ObservationController;

// Rutas de autenticación
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/google', [GoogleController::class, 'authenticate']);

Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('reset-password', [AuthController::class, 'resetPassword']);

// Rutas para la gestión de taxones
Route::prefix('taxa')->group(function () {
    Route::get('/', [TaxonController::class, 'index']); // Listar todos los taxones
    Route::get('/search', [TaxonController::class, 'search']); // Buscar taxones
    Route::get('/explore/colombia', [TaxonController::class, 'exploreColombiaSpecies']); // Explorar especies de Colombia
    Route::get('/{id}', [TaxonController::class, 'show']); // Obtener un taxón por ID
    Route::get('/observations/{taxonId}', [TaxonController::class, 'observations'])->name('taxa.observations'); // Obtener observaciones de un taxón
    Route::post('/{taxon}/sync-observations', [TaxonController::class, 'syncObservations']); // Sincronizar observaciones de un taxón
    Route::get('/{id}/related', [TaxonController::class, 'related']); // Especies relacionadas
     // Listar especies de un lugar específico
});

// Rutas de Contenido Educativo
use App\Http\Controllers\Api\EducationalContentController;

Route::prefix('educational-contents')->group(function () {
    Route::get('/', [EducationalContentController::class, 'index']);
    Route::get('/{id}', [EducationalContentController::class, 'show']);
});




// Rutas protegidas (requieren autenticación)
Route::middleware('auth:sanctum')->group(function () {
    // Perfil y logout
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    
});