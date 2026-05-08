<?php

namespace App\Enums;

enum StockUsageStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
