<?php

use App\Http\Controllers\Api\ApplicationQuestionController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\SignupController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\PermissionsController;
use App\Http\Controllers\Api\PipelineStageController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/signup', [SignupController::class, 'store']);

Route::post('/login', [LoginController::class, 'login']);
Route::post('/login/verify-otp', [LoginController::class, 'verifyOtp']);

Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('/reset-password', [PasswordResetController::class, 'reset']);

// Public — the invitation token itself is the credential, no login needed
Route::post('/accept-invite', [UserController::class, 'acceptInvite']);

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
});

// Public — no access token needed, the refresh token itself is the credential
Route::post('/refresh-token', [LoginController::class, 'refresh']);
