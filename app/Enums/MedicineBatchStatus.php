<?php

namespace App\Enums;

enum MedicineBatchStatus: string
{
    case Available = 'available';
    case Expired = 'expired';
    case Depleted = 'depleted';
    case Quarantined = 'quarantined';
}
