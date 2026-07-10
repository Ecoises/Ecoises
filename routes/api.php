<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Api\TaxonController;
use App\Http\Controllers\Api\ObservationController;

// Rutas de autenticación protegidas por limitador de tasa
Route::middleware('throttle:login')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/auth/google', [GoogleController::class, 'authenticate']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

// Rutas para la gestión de taxones
Route::prefix('taxa')->group(function () {
    Route::get('/', [TaxonController::class, 'index']);                          // Listar todos los taxones
    Route::get('/search', [TaxonController::class, 'search']);                   // Buscar taxones
    Route::get('/explore', [TaxonController::class, 'explore']);                 // Explorar catálogo local enriquecido
    Route::get('/{id}/related', [TaxonController::class, 'related']);            // Especies relacionadas (antes de /{id})
    Route::get('/{id}/observations', [TaxonController::class, 'observations']);  // Avistamientos locales del taxón
    Route::get('/{id}', [TaxonController::class, 'show']);                       // Obtener un taxón por ID
});

// Rutas de Contenido Educativo
use App\Http\Controllers\Api\EducationalContentController;

Route::prefix('educational-contents')->group(function () {
    Route::get('/', [EducationalContentController::class, 'index']);
    Route::get('/{id}', [EducationalContentController::class, 'show']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/educational-contents/{id}/start', [EducationalContentController::class, 'start']);
    Route::post('/lessons/{id}/complete', [\App\Http\Controllers\Api\LessonController::class, 'complete']);
    Route::post('/activities/{id}/attempt', [\App\Http\Controllers\Api\ActivityController::class, 'attempt']);
});

// ── Explorer Geográfico (GBIF proxy) ───────────────────────────────────────────
use App\Http\Controllers\Api\ExplorerController;

Route::get('/explorer/nearby', [ExplorerController::class, 'explore']);

// Ruta para Especies Diarias con IA
use App\Http\Controllers\Api\DailySpeciesController;
Route::get('/daily-curiosities', [DailySpeciesController::class, 'index']);
Route::get('/daily-recommendation', [DailySpeciesController::class, 'speciesOfTheDay']);

// ── Avistamientos / Observaciones ────────────────────────────────────────────
// Lectura pública
Route::get('/observations', [ObservationController::class, 'index']);
Route::get('/observations/{id}', [ObservationController::class, 'show']);

// Rutas protegidas (requieren autenticación)
Route::middleware('auth:sanctum')->group(function () {
    // Perfil y logout
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Registrar, actualizar, eliminar y reportar avistamientos
    Route::post('/observations', [ObservationController::class, 'store']);
    Route::post('/observations/{id}', [ObservationController::class, 'update']);
    Route::delete('/observations/{id}', [ObservationController::class, 'destroy']);
    Route::post('/observations/{id}/report', [ObservationController::class, 'report']);
});