<?php

namespace App\Enums;

enum SettingType: string
{
    case String = 'string';
    case Number = 'number';
    case Boolean = 'boolean';
    case Json = 'json';
}
