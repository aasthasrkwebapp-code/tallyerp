<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A company's list of addresses its members may open it from: single IPv4 / IPv6
 * addresses or CIDR ranges such as 203.0.113.0/24, one per line or comma-separated.
 */
class IpAllowList
{
    public const MAX_ENTRIES = 50;

    /**
     * @return array{0: list<string>, 1: list<string>} the entries and the ones that are not valid
     */
    public static function parse(?string $text): array
    {
        $entries = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            if (in_array($entry, $entries, true)) {
                continue;
            }
            self::valid($entry) ? $entries[] = $entry : $invalid[] = $entry;
        }

        return [$entries, $invalid];
    }

    public static function valid(string $entry): bool
    {
        [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 1 && (int) $bits <= $max;
    }

    /** @param  list<string>  $entries */
    public static function allows(?string $ip, array $entries): bool
    {
        return $entries === [] || ($ip !== null && IpUtils::checkIp($ip, $entries));
    }
}
