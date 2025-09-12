<?php

namespace App\Enums;

/**
 * Enumeration of ProcessState
 */
enum ProcessState: string
{
    case Waiting = 'waiting';
    case Running = 'running';
    case Failed = 'failed';
    case Done = 'done';
}
