<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tamper-proofs super_admin_audit_log: Postgres itself rejects UPDATE, DELETE and TRUNCATE on it,
 * so nobody — not even a bug, a stolen admin session or the app's own DB user — can rewrite history.
 * (A DB superuser can still drop the triggers; that needs DB access, not an app compromise.)
 * Note: because the trigger also blocks cascades, a super admin who has audit rows cannot be hard-deleted — admins are disabled instead.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return 'admin_db';
    }

    public function up(): void
    {
        $db = DB::connection('admin_db');

        $db->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION super_admin_audit_log_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'super_admin_audit_log is append-only (% blocked)', TG_OP USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $db->unprepared('CREATE TRIGGER audit_log_no_change BEFORE UPDATE OR DELETE ON super_admin_audit_log FOR EACH ROW EXECUTE FUNCTION super_admin_audit_log_immutable()');
        $db->unprepared('CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON super_admin_audit_log FOR EACH STATEMENT EXECUTE FUNCTION super_admin_audit_log_immutable()');
    }

    public function down(): void
    {
        $db = DB::connection('admin_db');
        $db->unprepared('DROP TRIGGER IF EXISTS audit_log_no_change ON super_admin_audit_log');
        $db->unprepared('DROP TRIGGER IF EXISTS audit_log_no_truncate ON super_admin_audit_log');
        $db->unprepared('DROP FUNCTION IF EXISTS super_admin_audit_log_immutable()');
    }
};
