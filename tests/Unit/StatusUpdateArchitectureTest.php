<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Arch test — 모든 상태 변경은 StateMachine 경유 (State Machine Spec §16 · Roadmap Phase 2 위험 항목).
 * app/ 전체에서 직접 status 갱신 패턴을 탐지한다.
 */
class StatusUpdateArchitectureTest extends TestCase
{
    private const ALLOWED_PATH_FRAGMENT = 'app/Services/Workflow/StateMachine';

    private const FORBIDDEN_PATTERNS = [
        '/->update\(\s*\[\s*[\'"]status[\'"]/' => "Eloquent update(['status' => …]) 직접 호출",
        '/->status\s*=\s*(?!=)/' => '모델 status 속성 직접 대입',
        '/UPDATE\s+\w+\s+SET\s+[^;]*\bstatus\s*=/i' => 'raw SQL로 status 갱신',
    ];

    public function test_no_direct_status_mutation_outside_state_machine(): void
    {
        $appPath = dirname(__DIR__, 2).'/app';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath));

        foreach ($iterator as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, self::ALLOWED_PATH_FRAGMENT)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            foreach (self::FORBIDDEN_PATTERNS as $pattern => $label) {
                if (preg_match($pattern, $source)) {
                    $violations[] = "{$path}: {$label}";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "상태 변경은 StateMachine 경유로만 허용된다:\n".implode("\n", $violations)
        );
    }
}
