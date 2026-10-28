<?php

/*
 * Single source of truth for Super Admin permissions: module => label + actions.
 * A permission is the string "module.action" (e.g. "companies.suspend").
 *
 * The Roles screen, the API validation and the route middleware ('admin.can:module.action') all read this file,
 * so adding a module or action here is the only change needed to make it assignable to roles.
 * Managing admins and roles is NOT listed on purpose: it is always Owner-only (see EnsureAdminIsOwner).
 */
return [
    'dashboard' => ['label' => 'Dashboard', 'actions' => [
        'view' => 'View dashboard',
    ]],
    'companies' => ['label' => 'Companies', 'actions' => [
        'view' => 'View companies',
        'suspend' => 'Suspend companies',
        'reactivate' => 'Reactivate companies',
        'extend_trial' => 'Extend trials',
        'impersonate' => 'Impersonate users (read-only)',
        'impersonate_full' => 'Impersonate users with edit access',
        'export' => 'Export all company data',
        'delete' => 'Permanently delete companies',
        'notes' => 'Add support notes',
    ]],
    'billing' => ['label' => 'Billing & Plans', 'actions' => [
        'view' => 'View plans, usage and payment events',
        'change_plan' => 'Change a company\'s plan manually',
        'override_limits' => 'Override seat and job limits',
        'notes' => 'Add billing notes',
    ]],
    'users' => ['label' => 'Users (all companies)', 'actions' => [
        'view' => 'Search users across companies',
        'deactivate' => 'Deactivate and reactivate users',
        'reset_password' => 'Send password reset emails',
    ]],
    'analytics' => ['label' => 'Growth Analytics', 'actions' => [
        'view' => 'View signups, conversion, churn and MRR',
    ]],
    'audit_log' => ['label' => 'Audit Log', 'actions' => [
        'view' => 'View audit log',
        'export' => 'Export audit log (CSV)',
    ]],
    'debugging' => ['label' => 'Debugging', 'actions' => [
        'view' => 'View failed events',
        'retry' => 'Retry failed events',
    ]],
    'system_health' => ['label' => 'System Health', 'actions' => [
        'view' => 'View system health',
    ]],
    'search' => ['label' => 'Search', 'actions' => [
        'view' => 'Use global search',
    ]],
    'feature_flags' => ['label' => 'Feature Flags', 'actions' => [
        'view' => 'View flags',
        'manage' => 'Create, edit and delete flags',
    ]],
    'blog' => ['label' => 'Blog', 'actions' => [
        'view' => 'View posts',
        'create' => 'Create posts',
        'edit' => 'Edit posts',
        'delete' => 'Delete posts',
    ]],
    'notifications' => ['label' => 'Notifications', 'actions' => [
        'broadcast' => 'Send platform broadcasts and view their history',
    ]],
    'platform' => ['label' => 'Announcements & Maintenance', 'actions' => [
        'view' => 'View the announcement and maintenance status',
        'announce' => 'Publish and clear the customer announcement banner',
        'maintenance' => 'Turn maintenance mode on and off',
    ]],
];
