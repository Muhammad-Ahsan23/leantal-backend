<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\ApplicationQuestionController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\SignupController;
use App\Http\Controllers\Api\CandidateController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\PermissionsController;
use App\Http\Controllers\Api\PipelineStageController;
use App\Http\Controllers\Api\Public\PublicCareersController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/signup', [SignupController::class, 'store']);

Route::post('/login', [LoginController::class, 'login']);
Route::post('/login/verify-otp', [LoginController::class, 'verifyOtp']);

Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('/reset-password', [PasswordResetController::class, 'reset']);

// Public — the invitation token itself is the credential, no login needed
Route::post('/accept-invite', [UserController::class, 'acceptInvite']);

// Public — candidate-facing careers page, completely unauthenticated
Route::get('/public/careers/{companySlug}', [PublicCareersController::class, 'index']);
Route::get('/public/careers/{companySlug}/jobs/{jobId}', [PublicCareersController::class, 'showJob']);
Route::post('/public/careers/{companySlug}/jobs/{jobId}/apply', [PublicCareersController::class, 'apply']);

// Protected routes — require a valid Sanctum bearer token
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::post('/logout-all', [LoginController::class, 'logoutAll']);
    Route::get('/my-permissions', [PermissionsController::class, 'index']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users/invite', [UserController::class, 'invite']);
    Route::delete('/users/{id}', [UserController::class, 'remove']);

    Route::get('/jobs', [JobController::class, 'index']);
    Route::post('/jobs', [JobController::class, 'store']);
    Route::get('/jobs/{id}', [JobController::class, 'show']);
    Route::patch('/jobs/{id}', [JobController::class, 'update']);
    Route::patch('/jobs/{id}/status', [JobController::class, 'updateStatus']);
    Route::post('/jobs/{id}/assign', [JobController::class, 'assign']);

    Route::get('/jobs/{jobId}/stages', [PipelineStageController::class, 'index']);
    Route::post('/jobs/{jobId}/stages', [PipelineStageController::class, 'store']);
    Route::patch('/jobs/{jobId}/stages/reorder', [PipelineStageController::class, 'reorder']);
    Route::patch('/jobs/{jobId}/stages/{stageId}', [PipelineStageController::class, 'update']);
    Route::delete('/jobs/{jobId}/stages/{stageId}', [PipelineStageController::class, 'destroy']);

    Route::get('/jobs/{jobId}/questions', [ApplicationQuestionController::class, 'index']);
    Route::post('/jobs/{jobId}/questions', [ApplicationQuestionController::class, 'store']);
    Route::patch('/jobs/{jobId}/questions/reorder', [ApplicationQuestionController::class, 'reorder']);
    Route::patch('/jobs/{jobId}/questions/{questionId}', [ApplicationQuestionController::class, 'update']);
    Route::delete('/jobs/{jobId}/questions/{questionId}', [ApplicationQuestionController::class, 'destroy']);

    Route::get('/candidates', [CandidateController::class, 'index']);
    Route::post('/candidates', [CandidateController::class, 'store']);
    Route::get('/candidates/{id}', [CandidateController::class, 'show']);
    Route::patch('/candidates/{id}/archive', [CandidateController::class, 'archive']);
    Route::delete('/candidates/{id}', [CandidateController::class, 'destroy']);
    Route::post('/candidates/{id}/assign', [CandidateController::class, 'assign']);
    Route::get('/candidates/{id}/notes', [CandidateController::class, 'listNotes']);
    Route::post('/candidates/{id}/notes', [CandidateController::class, 'addNote']);
    Route::get('/candidates/{id}/activity', [CandidateController::class, 'activity']);

    Route::get('/jobs/{jobId}/applications', [ApplicationController::class, 'index']);
    Route::get('/applications/{id}', [ApplicationController::class, 'show']);
    Route::patch('/applications/{id}/stage', [ApplicationController::class, 'moveStage']);

    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/{id}', [TaskController::class, 'show']);
    Route::patch('/tasks/{id}/status', [TaskController::class, 'updateStatus']);
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy']);
});

// Public — no access token needed, the refresh token itself is the credential
Route::post('/refresh-token', [LoginController::class, 'refresh']);
