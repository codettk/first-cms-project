<?php

namespace App\Enums;

/**
 * workflow_job_logs.level — DB Table Spec §4.10.
 */
enum LogLevel: string
{
    case Debug = 'DEBUG';
    case Info = 'INFO';
    case Warn = 'WARN';
    case Error = 'ERROR';
}
