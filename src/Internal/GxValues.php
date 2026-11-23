<?php

declare(strict_types=1);

namespace Youmad\Endurance\Kml\Internal;

use Youmad\Endurance\Kml\Exception\InvalidKml;

/** @internal */
final readonly class GxValues
{
    /** @return array{float,float,float}|null Empty means an explicit absent value, never zero. */
    public static function triple(string $value): ?array
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        $values = preg_split('/[\x20\x09\x0D\x0A]+/D', $value);
        if (false === $values || 3 !== count($values)) {
            throw new InvalidKml('GX values require three whitespace-separated numbers.');
        }
        $numbers = [];
        foreach ($values as $scalar) {
            if (strlen($scalar) > 128 || !preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $scalar)
                || !is_finite($number = (float) $scalar)) {
                throw new InvalidKml('Invalid or oversized GX numeric value.');
            }
            $numbers[] = $number;
        }

        return [$numbers[0], $numbers[1], $numbers[2]];
    }
}
