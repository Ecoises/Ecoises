<?php

/*
|--------------------------------------------------------------------------
| API de EcoIses
|--------------------------------------------------------------------------
| Organizada por módulo. Primero las rutas públicas y al final las que
| requieren token de Sanctum (header Authorization: Bearer <token>).
*/

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\ChallengeController;
use App\Http\Controllers\Api\DailySpeciesController;
use App\Http\Controllers\Api\EducationalContentController;
use App\Http\Controllers\Api\ExplorerController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\LearningDashboardController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\ObservationController;
use App\Http\Controllers\Api\TaxonController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;

// ── Autenticación (con límite de intentos) ──────────────────────────────────
Route::middleware('throttle:login')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/auth/google', [GoogleController::class, 'authenticate']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

// ── Biodiversidad ───────────────────────────────────────────────────────────
// Explorador: lista con foto (iNaturalist) y registros científicos (GBIF)
Route::get('/explorer', [ExplorerController::class, 'index']);
Route::get('/explorer/scientific', [ExplorerController::class, 'scientific']);

// Fichas de especies del catálogo local
Route::prefix('taxa')->group(function () {
    Route::get('/search', [TaxonController::class, 'search']);
    Route::get('/{id}/related', [TaxonController::class, 'related'])->whereNumber('id');
    Route::get('/{id}/observations', [TaxonController::class, 'observations'])->whereNumber('id');
    Route::get('/{id}', [TaxonController::class, 'show'])->whereNumber('id');
});

// Curiosidades y especie del día
Route::get('/daily-curiosities', [DailySpeciesController::class, 'index']);
Route::get('/daily-recommendation', [DailySpeciesController::class, 'speciesOfTheDay']);

// ── Retos de campo y avistamientos ──────────────────────────────────────────
Route::get('/challenges', [ChallengeController::class, 'index']);
Route::get('/challenges/{id}', [ChallengeController::class, 'show'])->whereNumber('id');
Route::get('/observations', [ObservationController::class, 'index']);
Route::get('/observations/{id}', [ObservationController::class, 'show']);

// ── Aprendizaje ─────────────────────────────────────────────────────────────
Route::get('/educational-contents', [EducationalContentController::class, 'index']);
Route::get('/educational-contents/{id}', [EducationalContentController::class, 'show']);
Route::get('/certificates/{verificationCode}', [CertificateController::class, 'show']);

// ── Comunidad ───────────────────────────────────────────────────────────────
Route::get('/announcements', [AnnouncementController::class, 'index']);
Route::get('/announcements/{slug}', [AnnouncementController::class, 'show']);

// ── Rutas que requieren sesión ──────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    // Cuenta
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Avistamientos (evidencia de retos)
    Route::post('/observations', [ObservationController::class, 'store']);
    Route::post('/observations/{id}', [ObservationController::class, 'update']);
    Route::delete('/observations/{id}', [ObservationController::class, 'destroy']);
    Route::post('/observations/{id}/report', [ObservationController::class, 'report']);

    // Aprendizaje y progreso
    Route::post('/educational-contents/{id}/start', [EducationalContentController::class, 'start']);
    Route::patch('/educational-contents/{id}/article-progress', [EducationalContentController::class, 'updateArticleProgress']);
    Route::post('/lessons/{id}/complete', [LessonController::class, 'complete']);
    Route::post('/activities/{id}/attempt', [ActivityController::class, 'attempt']);
    Route::get('/learning/dashboard', [LearningDashboardController::class, 'show']);
    Route::get('/my/certificates', [CertificateController::class, 'index']);

    // Opiniones
    Route::post('/educational-contents/{id}/feedback', [FeedbackController::class, 'storeContent']);
    Route::post('/feedback', [FeedbackController::class, 'storeGeneral']);
});
