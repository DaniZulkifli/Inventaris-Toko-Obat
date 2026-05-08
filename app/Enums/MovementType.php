<?php

namespace App\Enums;

enum MovementType: string
{
    case OpeningStock = 'opening_stock';
    case PurchaseIn = 'purchase_in';
    case SaleOut = 'sale_out';
    case UsageOut = 'usage_out';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case CancelUsage = 'cancel_usage';
    case CancelAdjustment = 'cancel_adjustment';
}
