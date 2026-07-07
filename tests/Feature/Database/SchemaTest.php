<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration Seed Spec §15 — 스키마 생성·partial index 검증.
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private const WORKFLOW_TABLES = [
        'contents', 'media_files', 'media_renditions',
        'workflow_templates', 'workflow_template_steps',
        'workflow_instances', 'workflow_jobs', 'workflow_job_dependencies',
        'workflow_job_histories', 'workflow_job_logs',
        'workflow_worker_agents', 'workflow_job_locks', 'workflow_job_progresses',
        'search_index_states', 'transcode_profiles',
        'admin_audit_logs', 'workflow_job_allowed_transitions',
    ];

    public function test_all_17_workflow_tables_exist(): void
    {
        $existing = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->pluck('table_name')
            ->all();

        foreach (self::WORKFLOW_TABLES as $table) {
            $this->assertContains($table, $existing, "missing table: {$table}");
        }
        $this->assertCount(17, self::WORKFLOW_TABLES);
    }

    public function test_media_renditions_variant_key_column(): void
    {
        $column = DB::table('information_schema.columns')
            ->where('table_name', 'media_renditions')
            ->where('column_name', 'variant_key')
            ->first();

        $this->assertNotNull($column, 'media_renditions.variant_key is required');
        $this->assertSame('NO', $column->is_nullable);
        $this->assertStringContainsString('default', $column->column_default);
        $this->assertEquals(50, $column->character_maximum_length);
    }

    public function test_workflow_jobs_priority_column(): void
    {
        $column = DB::table('information_schema.columns')
            ->where('table_name', 'workflow_jobs')
            ->where('column_name', 'priority')
            ->first();

        $this->assertNotNull($column, 'workflow_jobs.priority is required');
        $this->assertSame('NO', $column->is_nullable);
        $this->assertSame('smallint', $column->data_type);
        $this->assertStringContainsString('100', $column->column_default);
    }

    public function test_workflow_jobs_cancel_requested_at_column(): void
    {
        $exists = DB::table('information_schema.columns')
            ->where('table_name', 'workflow_jobs')
            ->where('column_name', 'cancel_requested_at')
            ->exists();

        $this->assertTrue($exists);
    }

    public function test_workflow_job_progresses_columns(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_name', 'workflow_job_progresses')
            ->pluck('data_type', 'column_name');

        $this->assertSame('numeric', $columns['progress_percent']);
        $this->assertArrayHasKey('progress_message', $columns->all());
        $this->assertArrayHasKey('estimated_remaining_sec', $columns->all());
        $this->assertArrayHasKey('updated_at', $columns->all());
    }

    public function test_partial_indexes_exist_with_where_clauses(): void
    {
        $indexes = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->pluck('indexdef', 'indexname');

        // Migration Seed Spec §12 — partial index 7종
        $expected = [
            'idx_jobs_queue' => "'READY'",
            'idx_jobs_running' => "'RUNNING'",
            'idx_jobs_failed' => "'FAILED'",
            'uq_instances_running' => "'RUNNING'",
            'uq_templates_active' => 'is_active',
            'idx_index_states_dirty' => "'PENDING'",
            'idx_logs_error' => "'ERROR'",
            // rendition upsert 키 3종 (variant_key 포함 — Transcode Profile Spec §10)
            'uq_rend_single' => 'page_no IS NULL',
            'uq_rend_page' => 'page_no IS NOT NULL',
            'uq_rend_timecode' => 'timecode_ms IS NOT NULL',
        ];

        foreach ($expected as $name => $whereFragment) {
            $this->assertArrayHasKey($name, $indexes->all(), "missing index: {$name}");
            $this->assertStringContainsString('WHERE', $indexes[$name], "{$name} must be a partial index");
            $this->assertStringContainsString($whereFragment, $indexes[$name]);
        }
    }

    public function test_rendition_unique_indexes_include_variant_key(): void
    {
        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'media_renditions')
            ->whereIn('indexname', ['uq_rend_single', 'uq_rend_page', 'uq_rend_timecode'])
            ->pluck('indexdef', 'indexname');

        $this->assertCount(3, $indexes);
        foreach ($indexes as $name => $def) {
            $this->assertStringContainsString('UNIQUE', $def);
            $this->assertStringContainsString('variant_key', $def, "{$name} must include variant_key");
        }
    }
}
