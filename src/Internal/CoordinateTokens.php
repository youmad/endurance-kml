<?php

declare(strict_types=1);

namespace Endurance\Kml\Internal;

use Endurance\Kml\Exception\InvalidKml;

/** Tokenize incrementally even when all coordinates share one XML text node. @internal */
final class CoordinateTokens
{
    private string $pending = '';

    /** @return \Generator<int, array{float,float,?float}> longitude, latitude, optional source altitude */
    public function feed(string $text, bool $last = false): \Generator
    {
        foreach (preg_split('/([\x20\x09\x0D\x0A]+)/', $text, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [] as $fragment) {
            if ('' === $fragment) {
                continue;
            }
            if (str_contains(" \t\r\n", $fragment[0])) {
                if ('' !== $this->pending) {
                    yield $this->take();
                }
            } else {
                if (strlen($this->pending) + strlen($fragment) > 128) {
                    throw new InvalidKml('KML coordinate tuple exceeds the supported bound.');
                }
                $this->pending .= $fragment;
            }
        }
        if ($last && '' !== $this->pending) {
            yield $this->take();
        }
    }

    /** @return array{float,float,?float} */
    private function take(): array
    {
        $parts = explode(',', $this->pending);
        $this->pending = '';
        if (count($parts) < 2 || count($parts) > 3) {
            throw new InvalidKml('KML coordinates require longitude,latitude[,altitude].');
        }
        $numbers = [];
        foreach ($parts as $part) {
            if (!preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $part) || !is_finite((float) $part)) {
                throw new InvalidKml('KML coordinate components must be finite numbers.');
            }
            $numbers[] = (float) $part;
        }
        if (abs($numbers[0]) > 180 || abs($numbers[1]) > 90) {
            throw new InvalidKml('KML coordinates exceed geographic bounds.');
        }

        return [$numbers[0], $numbers[1], $numbers[2] ?? null];
    }
}
