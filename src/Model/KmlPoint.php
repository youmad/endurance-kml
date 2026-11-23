<?php

declare(strict_types=1);

namespace Endurance\Kml\Model;

/** A null part denotes a standalone Point. */
final readonly class KmlPoint
{
    public function __construct(
        public int $placemarkIndex,
        public int $ordinal,
        public ?int $partIndex,
        public int $pointIndex,
        public float $latitude,
        public float $longitude,
        public ?float $altitude,
        public AltitudeMode $altitudeMode,
    ) {
        if ($placemarkIndex < 1 || $ordinal < 1 || $pointIndex < 0 || (null !== $partIndex && $partIndex < 0)
            || !is_finite($latitude) || abs($latitude) > 90 || !is_finite($longitude) || abs($longitude) > 180
            || (null !== $altitude && !is_finite($altitude))) {
            throw new \InvalidArgumentException('Invalid KML coordinate.');
        }
    }
}
