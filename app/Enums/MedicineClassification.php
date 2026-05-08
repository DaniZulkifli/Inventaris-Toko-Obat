<?php

namespace App\Enums;

enum MedicineClassification: string
{
    case ObatBebas = 'obat_bebas';
    case ObatBebasTerbatas = 'obat_bebas_terbatas';
    case ObatKeras = 'obat_keras';
    case VitaminSuplemen = 'vitamin_suplemen';
    case Alkes = 'alkes';
    case Other = 'other';
}
