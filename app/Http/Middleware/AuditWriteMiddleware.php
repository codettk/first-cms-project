<?php

namespace App\Http\Middleware;

use App\Services\Workflow\Admin\AuditContext;
use Closure;
use Illuminate\Http\Request;

/**
 * audit.write — 조작(POST/PATCH/DELETE) 라우트에만 부착.
 * admin_audit_logs 저장 경로를 활성화한다 — GET 라우트에는 부착하지 않으므로
 * GET에서의 감사 저장 시도는 AuditLogger가 차단한다(LogicException).
 */
class AuditWriteMiddleware
{
    public function __construct(private readonly AuditContext $context) {}

    public function handle(Request $request, Closure $next)
    {
        $this->context->enableWrite();

        return $next($request);
    }
}
