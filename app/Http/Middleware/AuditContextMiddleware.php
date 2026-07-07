<?php

namespace App\Http\Middleware;

use App\Services\Workflow\Admin\AuditContext;
use Closure;
use Illuminate\Http\Request;

/**
 * audit.context — 전 관리자 요청에서 actor/ip/UA 수집만 수행한다. 저장하지 않는다.
 */
class AuditContextMiddleware
{
    public function __construct(private readonly AuditContext $context) {}

    public function handle(Request $request, Closure $next)
    {
        $this->context->capture(
            $request->user()?->id,
            $request->ip(),
            $request->userAgent(),
        );

        return $next($request);
    }
}
