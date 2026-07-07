<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkflowTemplateSeeder extends Seeder
{
    /**
     * 템플릿 4종 + steps seed — Migration Seed Spec §10.2.
     * (code, version) / (template_id, job_type) 키 upsert로 재실행 멱등을 보장한다.
     *
     * step 형식: [job_type, step_order, is_required, max_retry, timeout_sec, depends_on, config]
     */
    public function run(): void
    {
        $templates = [
            [
                'code' => 'VIDEO_INGEST', 'name' => '영상 수급 워크플로우', 'media_type' => 'VIDEO',
                'steps' => [
                    ['TM',      1, true, 3, 600,  null,        null],
                    ['VERIFY',  2, true, 2, 300,  ['TM'],      null],
                    ['MA',      3, true, 3, 300,  ['VERIFY'],  null],
                    ['TC',      4, true, 2, 7200, ['MA'],      ['profile' => 'VIDEO_PROXY_720P']],
                    ['CA',      5, true, 3, 600,  ['TC'],      ['timecodes' => [5000, 10000, 20000]]],
                    ['INDEX',   6, true, 5, 300,  ['CA'],      null],
                    ['PUBLISH', 7, true, 3, 120,  ['INDEX'],   null],
                    ['CLEANUP', 8, true, 5, 600,  ['PUBLISH'], null],
                ],
            ],
            [
                'code' => 'IMAGE_INGEST', 'name' => '이미지 수급 워크플로우', 'media_type' => 'IMAGE',
                'steps' => [
                    ['TM',       1, true,  3, 600,  null,         null],
                    ['VERIFY',   2, true,  2, 300,  ['TM'],       null],
                    ['MA',       3, true,  3, 300,  ['VERIFY'],   null],
                    ['IMAGE_TC', 4, true,  3, 900,  ['MA'],       ['profile' => 'IMAGE_PROXY_WEBP_2048']],
                    ['CA',       5, true,  3, 600,  ['IMAGE_TC'], null],
                    ['INDEX',    6, true,  5, 300,  ['CA'],       null],
                    ['PUBLISH',  7, true,  3, 120,  ['INDEX'],    null],
                    ['CLEANUP',  8, true,  5, 600,  ['PUBLISH'],  null],
                    ['OCR',      9, false, 2, 1800, ['IMAGE_TC'], null],
                ],
            ],
            [
                'code' => 'AUDIO_INGEST', 'name' => '오디오 수급 워크플로우', 'media_type' => 'AUDIO',
                'steps' => [
                    ['TM',       1, true,  3, 600,  null,         null],
                    ['VERIFY',   2, true,  2, 300,  ['TM'],       null],
                    ['MA',       3, true,  3, 300,  ['VERIFY'],   null],
                    ['AUDIO_TC', 4, true,  3, 1800, ['MA'],       ['profile' => 'AUDIO_PROXY_AAC_128K']],
                    ['INDEX',    5, true,  5, 300,  ['AUDIO_TC'], null],
                    ['PUBLISH',  6, true,  3, 120,  ['INDEX'],    null],
                    ['CLEANUP',  7, true,  5, 600,  ['PUBLISH'],  null],
                    ['WAVEFORM', 8, false, 3, 600,  ['AUDIO_TC'], null],
                    ['STT',      9, false, 3, 600,  ['AUDIO_TC'], null],
                ],
            ],
            [
                'code' => 'DOCUMENT_INGEST', 'name' => '문서 수급 워크플로우', 'media_type' => 'DOC',
                'steps' => [
                    ['TM',           1,  true,  3, 600,  null,                          null],
                    ['VERIFY',       2,  true,  2, 300,  ['TM'],                        null],
                    ['MA',           3,  true,  3, 300,  ['VERIFY'],                    null],
                    ['DOC_PREVIEW',  4,  true,  2, 1800, ['MA'],                        ['profile' => 'DOC_PREVIEW_WEBP_144DPI']],
                    ['TEXT_EXTRACT', 5,  true,  3, 600,  ['MA'],                        null],
                    ['OCR',          6,  false, 2, 1800, ['DOC_PREVIEW', 'TEXT_EXTRACT'], null],
                    ['CA',           7,  true,  3, 600,  ['DOC_PREVIEW'],               null],
                    ['INDEX',        8,  true,  5, 300,  ['TEXT_EXTRACT', 'CA'],        null],
                    ['PUBLISH',      9,  true,  3, 120,  ['INDEX'],                     null],
                    ['CLEANUP',      10, true,  5, 600,  ['PUBLISH'],                   null],
                ],
            ],
        ];

        foreach ($templates as $template) {
            DB::table('workflow_templates')->updateOrInsert(
                ['code' => $template['code'], 'version' => 1],
                [
                    'name' => $template['name'],
                    'media_type' => $template['media_type'],
                    'is_active' => true,
                    'created_at' => now(),
                ]
            );

            $templateId = DB::table('workflow_templates')
                ->where('code', $template['code'])->where('version', 1)
                ->value('id');

            foreach ($template['steps'] as [$type, $order, $required, $retry, $timeout, $dependsOn, $config]) {
                DB::table('workflow_template_steps')->updateOrInsert(
                    ['template_id' => $templateId, 'job_type' => $type],
                    [
                        'step_order' => $order,
                        'is_required' => $required,
                        'max_retry' => $retry,
                        'timeout_sec' => $timeout,
                        'depends_on' => $dependsOn ? json_encode($dependsOn) : null,
                        'config' => $config ? json_encode($config) : null,
                    ]
                );
            }
        }
    }
}
