<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class EastAfricaTime
{
    private const TIMEZONE = 'Africa/Dar_es_Salaam';

    public static function iso8601(mixed $value): string
    {
        return Carbon::parse($value)
            ->setTimezone(self::TIMEZONE)
            ->format('Y-m-d\TH:i:s.vP');
    }
}
