<?php

namespace App\Http\Resources\Admin\Concerns;

/**
 * Master 경로 마스킹 — Controller Service Spec §12.
 * master/2026/07/1001_a1b2c3d4_src.mov → master/2026/07/1001****.mov
 */
trait MasksPaths
{
    public static function maskPath(string $path): string
    {
        return preg_replace('#([^/]{4})[^/]*(\.[a-z0-9]+)$#i', '$1****$2', $path);
    }

    /** payload/result 내 master 경로 값도 마스킹한다 (JobResource 공용) */
    public static function maskArrayPaths(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        array_walk_recursive($data, function (&$value) {
            if (is_string($value) && str_starts_with($value, 'master/')) {
                $value = self::maskPath($value);
            }
        });

        return $data;
    }
}
