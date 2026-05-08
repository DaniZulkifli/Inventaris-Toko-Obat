<?php

namespace App\Enums;

enum StockUsageType: string
{
    case Damaged = 'damaged';
    case Expired = 'expired';
    case Lost = 'lost';
    case Sample = 'sample';
    case ReturnSupplier = 'return_supplier';
    case InternalUse = 'internal_use';
    case Other = 'other';
}
