<?php

declare(strict_types=1);

namespace App\Model;

final class ConfluenceId
{
    private function __construct() {}

    public static function isValid(string $id): bool
    {
        if (preg_match('/^[1-9]\d*$/', $id) !== 1) {
            return false;
        }

        $normalised = ltrim($id, '0');
        $maximum = (string) PHP_INT_MAX;

        return strlen($normalised) < strlen($maximum)
            || (
                strlen($normalised) === strlen($maximum)
                && strcmp($normalised, $maximum) <= 0
            );
    }

    public static function toInt(string $id): int
    {
        if (!self::isValid($id)) {
            throw new \InvalidArgumentException('The Confluence ID must be a positive integer within the supported range.');
        }

        return (int) $id;
    }
}
