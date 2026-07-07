<?php

use App\Exceptions\WorkflowException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Admin Workflow API — 세션/CSRF 없는 JSON 계약 (Controller Service Spec §3)
            Route::group([], base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'audit.context' => App\Http\Middleware\AuditContextMiddleware::class,
            'audit.write' => App\Http\Middleware\AuditWriteMiddleware::class,
        ]);

        // 스켈레톤에 login 라우트가 없다 — 미인증 웹 요청은 루트로 (JSON은 401)
        $middleware->redirectGuestsTo(fn () => '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('admin/workflows*'),
        );

        // /admin/workflows* 요청은 ErrorResource 형태로 변환 — Controller Service Spec §13
        $isAdminApi = fn (Request $request): bool => $request->is('admin/workflows*');

        $errorResponse = function (int $status, string $code, string $message, array $extra = []) {
            return response()->json([
                'success' => false,
                'error' => ['code' => $code, 'message' => $message, ...$extra],
            ], $status);
        };

        $exceptions->render(function (WorkflowException $e, Request $request) use ($isAdminApi, $errorResponse) {
            if ($isAdminApi($request)) {
                return $errorResponse($e->httpStatus(), $e->errorCode(), $e->getMessage(), $e->errorContext());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isAdminApi, $errorResponse) {
            if ($isAdminApi($request)) {
                return $errorResponse(401, 'UNAUTHORIZED', '인증이 필요합니다.');
            }
        });

        // 프레임워크가 render 콜백 전에 AuthorizationException을 AccessDeniedHttpException으로 변환한다
        $exceptions->render(function (AuthorizationException|\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, Request $request) use ($isAdminApi, $errorResponse) {
            if ($isAdminApi($request)) {
                return $errorResponse(403, 'PERMISSION_DENIED', '권한이 없습니다.');
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($isAdminApi, $errorResponse) {
            if ($isAdminApi($request)) {
                return $errorResponse(404, 'NOT_FOUND', '대상을 찾을 수 없습니다.');
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isAdminApi, $errorResponse) {
            if ($isAdminApi($request)) {
                return $errorResponse(422, 'VALIDATION_ERROR', '입력 검증에 실패했습니다.', [
                    'details' => $e->errors(),
                ]);
            }
        });
    })->create();
