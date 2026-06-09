<?php

namespace Statikbe\FilamentSolaris\Enums;

enum BatchRunStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
