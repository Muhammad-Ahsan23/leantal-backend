<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\CandidateExportController;
use App\Http\Controllers\Api\CandidateImportController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminAuthController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminDashboardController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminCompanyController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminAuditController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminImpersonationController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminDebuggingController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminSystemHealthController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminSearchController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminFeatureFlagController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminBlogController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminNotificationController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\ApplicationQuestionController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\SignupController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CandidateController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CreemWebhookController;
use App\Http\Controllers\Api\CustomFieldController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\GmailPushWebhookController;
use App\Http\Controllers\Api\MicrosoftGraphWebhookController;
use App\Http\Controllers\Api\InterviewController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OAuthConnectController;
use App\Http\Controllers\Api\OAuthConnectionController;
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

// Public — Creem calls this directly; authenticated via HMAC signature
// instead of a Bearer token (see CreemWebhookController).
Route::post('/webhooks/creem', [CreemWebhookController::class, 'handle']);

// Public — Google Cloud Pub/Sub calls this to notify of Gmail changes;
// authenticated via a shared-secret query token instead of a Bearer
// token (see GmailPushWebhookController).
Route::post('/webhooks/gmail', [GmailPushWebhookController::class, 'handle']);

// Public — Microsoft Graph calls this directly (also handles the
// validation-token handshake on subscription creation); clientState
// used for authentication instead of a Bearer token or query secret.
Route::post('/webhooks/microsoft', [MicrosoftGraphWebhookController::class, 'handle']);
Route::get('/webhooks/microsoft', [MicrosoftGraphWebhookController::class, 'handle']);

// Public — Google redirects the browser here directly after consent;
// no Bearer token reaches this route, identity comes from 'state'
// (see OAuthConnectController).
Route::get('/oauth/google/callback', [OAuthConnectController::class, 'googleCallback']);

// Public — Microsoft redirects the browser here directly after consent.
Route::get('/oauth/microsoft/callback', [OAuthConnectController::class, 'microsoftCallback']);

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
    Route::get('/jobs/{id}/share-links', [JobController::class, 'shareLinks']);

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
    Route::get('/candidates/export', [CandidateExportController::class, 'export']);
    Route::post('/candidates/import/headers', [CandidateImportController::class, 'headers']);
    Route::post('/candidates/import/preview', [CandidateImportController::class, 'preview']);
    Route::post('/candidates/import/confirm', [CandidateImportController::class, 'confirm']);
    Route::get('/candidates/{id}', [CandidateController::class, 'show']);
    Route::patch('/candidates/{id}/archive', [CandidateController::class, 'archive']);
    Route::patch('/candidates/{id}/restore', [CandidateController::class, 'restore']);
    Route::delete('/candidates/{id}', [CandidateController::class, 'destroy']);
    Route::post('/candidates/{id}/assign', [CandidateController::class, 'assign']);
    Route::get('/candidates/{id}/notes', [CandidateController::class, 'listNotes']);
    Route::post('/candidates/{id}/notes', [CandidateController::class, 'addNote']);
    Route::get('/candidates/{id}/resume-url', [CandidateController::class, 'resumeUrl']);
    Route::get('/candidates/{id}/activity', [CandidateController::class, 'activity']);

    Route::get('/jobs/{jobId}/applications', [ApplicationController::class, 'index']);
    Route::get('/applications/{id}', [ApplicationController::class, 'show']);
    Route::patch('/applications/{id}/stage', [ApplicationController::class, 'moveStage']);

    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/{id}', [TaskController::class, 'show']);
    Route::patch('/tasks/{id}/status', [TaskController::class, 'updateStatus']);
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy']);

    Route::get('/interviews', [InterviewController::class, 'index']);
    Route::post('/interviews', [InterviewController::class, 'store']);
    Route::get('/interviews/{id}', [InterviewController::class, 'show']);
    Route::patch('/interviews/{id}', [InterviewController::class, 'update']);
    Route::delete('/interviews/{id}', [InterviewController::class, 'destroy']);

    Route::get('/oauth/connections', [OAuthConnectionController::class, 'index']);
    Route::delete('/oauth/connections/{provider}', [OAuthConnectionController::class, 'disconnect']);
    Route::get('/oauth/google/connect', [OAuthConnectController::class, 'connectGoogle']);
    // PRD Section 80 — "[Exit Impersonation]" button, called by the
    // frontend using the active impersonation token itself.
    Route::post('/exit-impersonation', [ImpersonationController::class, 'exit']);
    Route::get('/oauth/microsoft/connect', [OAuthConnectController::class, 'connectMicrosoft']);

    Route::get('/email-templates', [EmailTemplateController::class, 'index']);
    Route::post('/email-templates', [EmailTemplateController::class, 'store']);
    Route::get('/email-templates/{id}', [EmailTemplateController::class, 'show']);
    Route::patch('/email-templates/{id}', [EmailTemplateController::class, 'update']);
    Route::delete('/email-templates/{id}', [EmailTemplateController::class, 'destroy']);

    Route::get('/candidates/{candidateId}/emails', [EmailController::class, 'index']);
    Route::post('/candidates/{candidateId}/emails', [EmailController::class, 'store']);

    // PRD Section 53 — the user's own inbox (Inbox / Sent). Strictly personal.
    Route::get('/emails', [InboxController::class, 'index']);
    Route::get('/emails/unread-count', [InboxController::class, 'unreadCount']);
    Route::post('/emails/mark-all-read', [InboxController::class, 'markAllRead']);

    Route::get('/company', [CompanyController::class, 'show']);
    Route::patch('/company', [CompanyController::class, 'update']);
    Route::post('/company/transfer-ownership', [CompanyController::class, 'transferOwnership']);
    Route::post('/company/delete', [CompanyController::class, 'delete']);

    Route::get('/billing/plans', [BillingController::class, 'plans']);
    Route::post('/billing/checkout', [BillingController::class, 'checkout']);
    Route::post('/billing/portal', [BillingController::class, 'portal']);

    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::patch('/departments/{id}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{id}', [DepartmentController::class, 'destroy']);

    Route::get('/activity', [ActivityController::class, 'index']);

    Route::get('/me', [AccountController::class, 'show']);
    Route::patch('/me', [AccountController::class, 'update']);
    Route::post('/me/change-password', [AccountController::class, 'changePassword']);

    Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index']);
    Route::patch('/notification-preferences', [NotificationPreferenceController::class, 'update']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/broadcast', [NotificationController::class, 'broadcast']);

    Route::get('/custom-fields', [CustomFieldController::class, 'index']);
    Route::post('/custom-fields', [CustomFieldController::class, 'store']);
    Route::patch('/custom-fields/{id}', [CustomFieldController::class, 'update']);
    Route::delete('/custom-fields/{id}', [CustomFieldController::class, 'destroy']);
    Route::post('/custom-fields/values', [CustomFieldController::class, 'setValues']);
    Route::get('/custom-fields/values/{entityType}/{entityId}', [CustomFieldController::class, 'getValues']);
});

// Public — no access token needed, the refresh token itself is the credential
Route::post('/refresh-token', [LoginController::class, 'refresh']);

// ============================================================
// SUPER ADMIN — PRD Section 76-85, 145. Completely separate auth
// system (EnsureSuperAdmin middleware, not auth:sanctum) — see
// bootstrap/app.php for the middleware alias registration.
// ============================================================
Route::prefix('super-admin')->group(function () {
    // Public — no admin session exists yet at this point
    Route::post('/auth/mfa/enroll', [SuperAdminAuthController::class, 'startMfaEnrollment']);
    Route::post('/auth/mfa/confirm', [SuperAdminAuthController::class, 'confirmMfaEnrollment']);
    Route::post('/auth/login', [SuperAdminAuthController::class, 'login']);

    // Protected — EnsureSuperAdmin middleware
    Route::middleware('super-admin')->group(function () {
        Route::post('/auth/logout', [SuperAdminAuthController::class, 'logout']);
        Route::get('/dashboard', [SuperAdminDashboardController::class, 'index']);
        Route::get('/companies', [SuperAdminCompanyController::class, 'index']);
        Route::get('/companies/{companyId}', [SuperAdminCompanyController::class, 'show']);
        Route::post('/companies/{companyId}/suspend', [SuperAdminCompanyController::class, 'suspend']);
        Route::post('/companies/{companyId}/reactivate', [SuperAdminCompanyController::class, 'reactivate']);
        Route::post('/companies/{companyId}/users/{userId}/impersonate', [SuperAdminImpersonationController::class, 'start']);
        Route::get('/audit-log', [SuperAdminAuditController::class, 'index']);
        Route::get('/debugging', [SuperAdminDebuggingController::class, 'index']);
        Route::post('/debugging/{eventId}/retry', [SuperAdminDebuggingController::class, 'retry']);
        Route::get('/system-health', [SuperAdminSystemHealthController::class, 'index']);
        Route::get('/search', [SuperAdminSearchController::class, 'index']);

        Route::get('/feature-flags', [SuperAdminFeatureFlagController::class, 'index']);
        Route::post('/feature-flags', [SuperAdminFeatureFlagController::class, 'save']);
        Route::delete('/feature-flags/{id}', [SuperAdminFeatureFlagController::class, 'destroy']);

        Route::get('/blog', [SuperAdminBlogController::class, 'index']);
        Route::get('/blog/{id}', [SuperAdminBlogController::class, 'show']);
        Route::post('/blog', [SuperAdminBlogController::class, 'store']);
        Route::patch('/blog/{id}', [SuperAdminBlogController::class, 'update']);
        Route::delete('/blog/{id}', [SuperAdminBlogController::class, 'destroy']);

        Route::post('/notifications/broadcast', [SuperAdminNotificationController::class, 'broadcast']);
    });
});
