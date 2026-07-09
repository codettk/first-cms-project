<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * OpenAPI ↔ 라우트 계약 대조 — Revision Checklist §9-③.
 * x-permission ↔ route can 미들웨어 · 경로/메서드 존재 · retry-batch 선순위.
 * Alerts API 포함 전 계약(17엔드포인트)을 라우트와 대조한다 (ADR-0006).
 */
class OpenApiContractTest extends TestCase
{
    private const array DEFERRED_PATHS = []; // 보류 경로 없음 — Alerts는 ADR-0006으로 구현

    /** @return array<string, array<string, array<string, mixed>>> */
    private function contractPaths(): array
    {
        $spec = Yaml::parseFile(base_path('openapi.yaml'));

        return $spec['paths'];
    }

    /** @return array<string, array{methods: list<string>, permission: ?string}> Laravel 라우트 인덱스 */
    private function laravelRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'admin/workflows')) {
                continue;
            }

            // /contents/{content} → /contents/{id} 정규화 (파라미터 이름 무시)
            $normalized = preg_replace('/\{[^}]+\}/', '{id}', substr($route->uri(), strlen('admin/workflows')));

            $permission = null;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                    $permission = substr($middleware, 4);
                }
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $routes[strtolower($method).' '.$normalized] = ['permission' => $permission];
            }
        }

        return $routes;
    }

    public function test_every_contract_operation_has_matching_route_and_permission(): void
    {
        $routes = $this->laravelRoutes();
        $missing = [];
        $permissionMismatch = [];

        foreach ($this->contractPaths() as $path => $operations) {
            if (in_array($path, self::DEFERRED_PATHS, true)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'patch', 'delete', 'put'], true)) {
                    continue;
                }

                $key = "{$method} {$path}";

                if (! array_key_exists($key, $routes)) {
                    $missing[] = $key;

                    continue;
                }

                $expected = $operation['x-permission'] ?? null;
                if ($expected !== null && $routes[$key]['permission'] !== $expected) {
                    $permissionMismatch[] = "{$key}: 계약 {$expected} ↔ 라우트 ".($routes[$key]['permission'] ?? '(없음)');
                }
            }
        }

        $this->assertSame([], $missing, "계약에 있으나 라우트가 없는 오퍼레이션:\n".implode("\n", $missing));
        $this->assertSame([], $permissionMismatch, "x-permission 불일치:\n".implode("\n", $permissionMismatch));
    }

    public function test_no_extra_admin_routes_outside_contract(): void
    {
        $contractKeys = [];
        foreach ($this->contractPaths() as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['get', 'post', 'patch', 'delete', 'put'], true)) {
                    $contractKeys[] = "{$method} {$path}";
                }
            }
        }

        $extra = array_diff(array_keys($this->laravelRoutes()), $contractKeys);

        $this->assertSame([], array_values($extra), '계약에 없는 admin 라우트: '.implode(', ', $extra));
    }

    public function test_retry_batch_route_is_declared_before_job_parameter_route(): void
    {
        $uris = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('POST', $route->methods(), true) && str_starts_with($route->uri(), 'admin/workflows/jobs')) {
                $uris[] = $route->uri();
            }
        }

        $batchIndex = array_search('admin/workflows/jobs/retry-batch', $uris, true);
        $paramIndex = collect($uris)->search(fn ($uri) => str_contains($uri, '{job}'));

        $this->assertNotFalse($batchIndex, 'retry-batch 라우트가 없다');
        $this->assertTrue($batchIndex < $paramIndex, 'retry-batch가 jobs/{job}보다 뒤에 선언되었다');
    }
}
