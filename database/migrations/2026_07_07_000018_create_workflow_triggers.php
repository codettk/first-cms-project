<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) 상태 전이 검증 — 허용 전이표 기반 2차 방어선 (Migration Seed Spec §11)
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_job_transition() RETURNS trigger AS $$
            BEGIN
              IF OLD.status = NEW.status THEN RETURN NEW; END IF;
              IF NOT EXISTS (SELECT 1 FROM workflow_job_allowed_transitions
                             WHERE from_status = OLD.status AND to_status = NEW.status) THEN
                RAISE EXCEPTION 'invalid job transition: % -> % (job %)', OLD.status, NEW.status, OLD.id;
              END IF;
              RETURN NEW;
            END $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_job_transition BEFORE UPDATE OF status ON workflow_jobs
            FOR EACH ROW EXECUTE FUNCTION check_job_transition();
        SQL);

        // 2) updated_at 자동 갱신 (공용)
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger AS $$
            BEGIN NEW.updated_at := now(); RETURN NEW; END $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_jobs_updated BEFORE UPDATE ON workflow_jobs
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            CREATE TRIGGER trg_contents_updated BEFORE UPDATE ON contents
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            CREATE TRIGGER trg_media_files_updated BEFORE UPDATE ON media_files
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            CREATE TRIGGER trg_workers_updated BEFORE UPDATE ON workflow_worker_agents
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);

        // 3) DELETED 콘텐츠 job 생성 금지
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_job_insert() RETURNS trigger AS $$
            BEGIN
              IF EXISTS (SELECT 1 FROM contents WHERE id = NEW.content_id AND status = 'DELETED') THEN
                RAISE EXCEPTION 'cannot create job for DELETED content %', NEW.content_id;
              END IF;
              RETURN NEW;
            END $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_job_insert_guard BEFORE INSERT ON workflow_jobs
            FOR EACH ROW EXECUTE FUNCTION guard_job_insert();
        SQL);

        // 4·5) append-only 보호 (histories, audit_logs 공용)
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_mutation() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION '% is append-only', TG_TABLE_NAME; END $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_histories_readonly BEFORE UPDATE OR DELETE ON workflow_job_histories
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation();
            CREATE TRIGGER trg_audit_readonly BEFORE UPDATE OR DELETE ON admin_audit_logs
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_audit_readonly ON admin_audit_logs;
            DROP TRIGGER IF EXISTS trg_histories_readonly ON workflow_job_histories;
            DROP TRIGGER IF EXISTS trg_job_insert_guard ON workflow_jobs;
            DROP TRIGGER IF EXISTS trg_workers_updated ON workflow_worker_agents;
            DROP TRIGGER IF EXISTS trg_media_files_updated ON media_files;
            DROP TRIGGER IF EXISTS trg_contents_updated ON contents;
            DROP TRIGGER IF EXISTS trg_jobs_updated ON workflow_jobs;
            DROP TRIGGER IF EXISTS trg_job_transition ON workflow_jobs;
            DROP FUNCTION IF EXISTS forbid_mutation();
            DROP FUNCTION IF EXISTS guard_job_insert();
            DROP FUNCTION IF EXISTS set_updated_at();
            DROP FUNCTION IF EXISTS check_job_transition();
        SQL);
    }
};
