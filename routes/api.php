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
use App\Http\Controllers\Api\SuperAdmin\SuperAdminAnalyticsController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminBillingController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminCompanyDataController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminImpersonationController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminUserController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminDebuggingController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminSystemHealthController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminSearchController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminFeatureFlagController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminBlogController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminNotificationController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminRoleController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminAdminController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminAlertsController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminPlatformController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminRegionHealthController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminSecurityController;
use App\Http\Controllers\Api\SuperAdmin\SuperAdminSupportNoteController;
use App\Http\Controllers\Api\PlatformStatusController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\ApplicationQuestionController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\SupportController;
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
use App\Http\Controllers\Api\TaskAttachmentController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/signup', [SignupController::class, 'store']);

Route::post('/login', [LoginController::class, 'login']);
Route::post('/login/verify-otp', [LoginController::class, 'verifyOtp'])->middleware('throttle:10,1');
Route::post('/login/resend-otp', [LoginController::class, 'resendOtp'])->middleware('throttle:10,1');

Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');

// Public — the invitation token itself is the credential, no login needed
Route::post('/accept-invite', [UserController::class, 'acceptInvite'])->middleware('throttle:10,1');

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
// Public — Zoom redirects the browser here after consent.
Route::get('/oauth/zoom/callback', [OAuthConnectController::class, 'zoomCallback']);

// Public — banner + maintenance state for the app (cached for a few seconds)
Route::get('/platform-status', [PlatformStatusController::class, 'show'])->middleware('throttle:60,1');

// Public — candidate-facing careers page, completely unauthenticated
Route::get('/public/careers/{companySlug}', [PublicCareersController::class, 'index']);
Route::get('/public/careers/{companySlug}/jobs/{jobId}', [PublicCareersController::class, 'showJob']);
Route::post('/public/careers/{companySlug}/jobs/{jobId}/apply', [PublicCareersController::class, 'apply']);

// Protected routes — require a valid Sanctum bearer token
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::post('/logout-all', [LoginController::class, 'logoutAll']);
    Route::get('/my-permissions', [PermissionsController::class, 'index']);
    Route::get('/support/tawk-identity', [SupportController::class, 'tawkIdentity']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users/invite', [UserController::class, 'invite']);
    Route::get('/users/invitations', [UserController::class, 'invitations']);
    Route::post('/users/invitations/{id}/resend', [UserController::class, 'resendInvitation'])->middleware('throttle:10,1');
    Route::delete('/users/invitations/{id}', [UserController::class, 'revokeInvitation']);
    Route::get('/users/{id}/workload', [UserController::class, 'workload']);
    Route::delete('/users/{id}', [UserController::class, 'remove']);
    Route::patch('/users/{id}', [UserController::class, 'update']);

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
    Route::patch('/candidates/{id}', [CandidateController::class, 'update']);
    Route::post('/candidates/{id}/resume', [CandidateController::class, 'replaceResume']);
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
    Route::get('/applications/{id}/answers', [ApplicationController::class, 'answers']);
    Route::get('/applications/{id}/answers/{answerId}/file-url', [ApplicationController::class, 'answerFileUrl']);
    Route::patch('/applications/{id}/stage', [ApplicationController::class, 'moveStage']);

    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/{id}', [TaskController::class, 'show']);
    Route::patch('/tasks/{id}', [TaskController::class, 'update']);
    Route::patch('/tasks/{id}/status', [TaskController::class, 'updateStatus']);
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy']);
    Route::post('/tasks/{id}/attachments', [TaskAttachmentController::class, 'store']);
    Route::get('/tasks/{id}/attachments/{attachmentId}/url', [TaskAttachmentController::class, 'url']);
    Route::delete('/tasks/{id}/attachments/{attachmentId}', [TaskAttachmentController::class, 'destroy']);

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
    Route::get('/oauth/zoom/connect', [OAuthConnectController::class, 'connectZoom']);

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
Route::post('/refresh-token', [LoginController::class, 'refresh'])->middleware('throttle:30,1');

// ============================================================
// SUPER ADMIN — PRD Section 76-85, 145. Completely separate auth
// system (EnsureSuperAdmin middleware, not auth:sanctum) — see
// bootstrap/app.php for the middleware alias registration.
// ============================================================
Route::prefix('super-admin')->middleware(\App\Http\Middleware\EnforceAdminIpAllowlist::class)->group(function () {
    // Public — no admin session exists yet at this point
    Route::post('/auth/mfa/enroll', [SuperAdminAuthController::class, 'startMfaEnrollment']);
    Route::post('/auth/mfa/confirm', [SuperAdminAuthController::class, 'confirmMfaEnrollment']);
    Route::post('/auth/login', [SuperAdminAuthController::class, 'login']);

    // Protected — EnsureSuperAdmin middleware
    Route::middleware('super-admin')->group(function () {
        Route::post('/auth/logout', [SuperAdminAuthController::class, 'logout']);
        Route::get('/me', [SuperAdminAuthController::class, 'me']);
        Route::post('/auth/step-up', [SuperAdminAuthController::class, 'stepUp']);

        // Every route below needs a specific permission (config/admin_permissions.php).
        Route::get('/dashboard', [SuperAdminDashboardController::class, 'index'])->middleware('admin.can:dashboard.view');

        Route::get('/companies', [SuperAdminCompanyController::class, 'index'])->middleware('admin.can:companies.view');
        Route::get('/companies/{companyId}', [SuperAdminCompanyController::class, 'show'])->middleware('admin.can:companies.view');
        Route::post('/companies/{companyId}/suspend', [SuperAdminCompanyController::class, 'suspend'])->middleware(['admin.can:companies.suspend', 'admin.fresh']);
        Route::post('/companies/{companyId}/reactivate', [SuperAdminCompanyController::class, 'reactivate'])->middleware('admin.can:companies.reactivate');
        Route::post('/companies/{companyId}/extend-trial', [SuperAdminCompanyController::class, 'extendTrial'])->middleware('admin.can:companies.extend_trial');
        Route::post('/companies/{companyId}/users/{userId}/impersonate', [SuperAdminImpersonationController::class, 'start'])->middleware(['admin.can:companies.impersonate', 'admin.fresh']);

        // Business control: billing & limits, GDPR export/deletion, users across companies, growth numbers.
        Route::get('/companies/{companyId}/billing', [SuperAdminBillingController::class, 'show'])->middleware('admin.can:billing.view');
        Route::post('/companies/{companyId}/plan', [SuperAdminBillingController::class, 'changePlan'])->middleware(['admin.can:billing.change_plan', 'admin.fresh']);
        Route::put('/companies/{companyId}/limits', [SuperAdminBillingController::class, 'setLimits'])->middleware(['admin.can:billing.override_limits', 'admin.fresh']);
        Route::post('/companies/{companyId}/billing-notes', [SuperAdminBillingController::class, 'addNote'])->middleware('admin.can:billing.notes');
        Route::get('/companies/{companyId}/export', [SuperAdminCompanyDataController::class, 'export'])->middleware(['admin.can:companies.export', 'admin.fresh']);
        Route::delete('/companies/{companyId}', [SuperAdminCompanyDataController::class, 'destroy'])->middleware(['admin.can:companies.delete', 'admin.fresh']);
        Route::get('/users', [SuperAdminUserController::class, 'index'])->middleware('admin.can:users.view');
        Route::post('/companies/{companyId}/users/{userId}/status', [SuperAdminUserController::class, 'setStatus'])->middleware(['admin.can:users.deactivate', 'admin.fresh']);
        Route::post('/companies/{companyId}/users/{userId}/password-reset', [SuperAdminUserController::class, 'sendPasswordReset'])->middleware(['admin.can:users.reset_password', 'admin.fresh']);
        Route::get('/analytics', [SuperAdminAnalyticsController::class, 'index'])->middleware('admin.can:analytics.view');

        Route::get('/audit-log', [SuperAdminAuditController::class, 'index'])->middleware('admin.can:audit_log.view');
        Route::get('/audit-log/export', [SuperAdminAuditController::class, 'export'])->middleware(['admin.can:audit_log.export', 'admin.fresh']);
        Route::get('/debugging', [SuperAdminDebuggingController::class, 'index'])->middleware('admin.can:debugging.view');
        Route::post('/debugging/{eventId}/retry', [SuperAdminDebuggingController::class, 'retry'])->middleware('admin.can:debugging.retry');
        Route::get('/system-health', [SuperAdminSystemHealthController::class, 'index'])->middleware('admin.can:system_health.view');
        Route::get('/search', [SuperAdminSearchController::class, 'index'])->middleware('admin.can:search.view');

        Route::get('/feature-flags', [SuperAdminFeatureFlagController::class, 'index'])->middleware('admin.can:feature_flags.view');
        Route::post('/feature-flags', [SuperAdminFeatureFlagController::class, 'save'])->middleware('admin.can:feature_flags.manage');
        Route::delete('/feature-flags/{id}', [SuperAdminFeatureFlagController::class, 'destroy'])->middleware('admin.can:feature_flags.manage');

        Route::get('/blog', [SuperAdminBlogController::class, 'index'])->middleware('admin.can:blog.view');
        Route::get('/blog/{id}', [SuperAdminBlogController::class, 'show'])->middleware('admin.can:blog.view');
        Route::post('/blog', [SuperAdminBlogController::class, 'store'])->middleware('admin.can:blog.create');
        Route::patch('/blog/{id}', [SuperAdminBlogController::class, 'update'])->middleware('admin.can:blog.edit');
        Route::delete('/blog/{id}', [SuperAdminBlogController::class, 'destroy'])->middleware('admin.can:blog.delete');

        Route::post('/notifications/broadcast', [SuperAdminNotificationController::class, 'broadcast'])->middleware(['admin.can:notifications.broadcast', 'admin.fresh']);
        Route::get('/notifications/history', [SuperAdminNotificationController::class, 'history'])->middleware('admin.can:notifications.broadcast');

        Route::get('/platform', [SuperAdminPlatformController::class, 'show'])->middleware('admin.can:platform.view');
        Route::put('/platform/announcement', [SuperAdminPlatformController::class, 'publishAnnouncement'])->middleware(['admin.can:platform.announce', 'admin.fresh']);
        Route::delete('/platform/announcement', [SuperAdminPlatformController::class, 'clearAnnouncement'])->middleware('admin.can:platform.announce');
        Route::put('/platform/maintenance', [SuperAdminPlatformController::class, 'enableMaintenance'])->middleware(['admin.can:platform.maintenance', 'admin.fresh']);
        Route::delete('/platform/maintenance', [SuperAdminPlatformController::class, 'disableMaintenance'])->middleware('admin.can:platform.maintenance');

        Route::get('/companies/{companyId}/support-notes', [SuperAdminSupportNoteController::class, 'index'])->middleware('admin.can:companies.view');
        Route::post('/companies/{companyId}/support-notes', [SuperAdminSupportNoteController::class, 'store'])->middleware('admin.can:companies.notes');

        Route::get('/alerts', [SuperAdminAlertsController::class, 'index'])->middleware('admin.can:system_health.view');
        Route::get('/region-health', [SuperAdminRegionHealthController::class, 'index'])->middleware('admin.can:system_health.view');
        Route::get('/region-health/consistency', [SuperAdminRegionHealthController::class, 'consistency'])->middleware('admin.can:system_health.view');

        // Owner-only: who the admins are and what each role may do. Changes also need a fresh authenticator code.
        Route::middleware('admin.owner')->group(function () {
            Route::get('/roles', [SuperAdminRoleController::class, 'index']);
            Route::get('/admins', [SuperAdminAdminController::class, 'index']);
            Route::get('/security/ip-allowlist', [SuperAdminSecurityController::class, 'show']);

            Route::middleware('admin.fresh')->group(function () {
                Route::post('/roles', [SuperAdminRoleController::class, 'store']);
                Route::patch('/roles/{id}', [SuperAdminRoleController::class, 'update']);
                Route::delete('/roles/{id}', [SuperAdminRoleController::class, 'destroy']);

                Route::put('/security/ip-allowlist', [SuperAdminSecurityController::class, 'update']);

                Route::post('/admins', [SuperAdminAdminController::class, 'store']);
                Route::patch('/admins/{id}', [SuperAdminAdminController::class, 'update']);
                Route::post('/admins/{id}/reset-mfa', [SuperAdminAdminController::class, 'resetMfa']);
            });
        });
    });
});
