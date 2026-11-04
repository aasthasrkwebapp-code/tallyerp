<?php

namespace App\Support;

/**
 * Common TDS sections with their usual rates (%) when the deductee has a PAN.
 * Without a PAN, TDS is deducted at 20% (section 206AA).
 */
class Tds
{
    public const SECTIONS = [
        '194C-I' => ['194C', 'Contractor - individual/HUF', 1],
        '194C-O' => ['194C', 'Contractor - others', 2],
        '194H' => ['194H', 'Commission or brokerage', 2],
        '194I-PM' => ['194I', 'Rent - plant & machinery', 2],
        '194I-LB' => ['194I', 'Rent - land & building', 10],
        '194J-T' => ['194J', 'Fees - technical services', 2],
        '194J-P' => ['194J', 'Fees - professional services', 10],
        '194Q' => ['194Q', 'Purchase of goods', 0.1],
    ];

    public const NO_PAN_RATE = 20;

    public static function label(?string $key): ?string
    {
        return $key && isset(self::SECTIONS[$key])
            ? self::SECTIONS[$key][0].' · '.self::SECTIONS[$key][1]
            : null;
    }

    public static function rate(string $key, ?string $pan): float
    {
        return $pan ? (float) self::SECTIONS[$key][2] : (float) self::NO_PAN_RATE;
    }
}
