<?php

declare(strict_types=1);

namespace Youmad\Endurance\Kml\Model;

/** A missing GX position retains its sample/time and splits geometry. */
final readonly class KmlPathPoint
{
    public function __construct(
        public int $placemarkIndex,
        public int $ordinal,
        public ?int $partIndex,
        public int $pointIndex,
        public ?float $latitude,
        public ?float $longitude,
        public ?float $altitude,
        public AltitudeMode $altitudeMode,
        public ?int $trackIndex = null,
        public ?int $trackSampleIndex = null,
        public ?string $when = null,
    ) {
        if ($placemarkIndex < 1 || $ordinal < 1 || $pointIndex < 0 || (null !== $partIndex && $partIndex < 0)
            || (null === $latitude) !== (null === $longitude) || (null === $latitude && (null !== $partIndex || null !== $altitude))
            || (null !== $latitude && (!is_finite($latitude) || abs($latitude) > 90))
            || (null !== $longitude && (!is_finite($longitude) || abs($longitude) > 180))
            || (null !== $altitude && !is_finite($altitude)) || (null === $partIndex && 0 !== $pointIndex) || (null !== $trackIndex && null !== $latitude && null === $partIndex)
            || (null === $trackIndex ? (null !== $trackSampleIndex || null !== $when || null === $latitude)
                : ($trackIndex < 0 || null === $trackSampleIndex || $trackSampleIndex < 0 || null === $when || '' === $when))) {
            throw new \InvalidArgumentException('Invalid KML path sample.');
        }
    }
}
