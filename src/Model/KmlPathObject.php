<?php

declare(strict_types=1);

namespace Endurance\Kml\Model;

final readonly class KmlPathObject
{
    public function __construct(
        public int $index,
        public ?string $name,
        public ?string $description,
        public int $geometryPartCount,
        public int $geometryPointCount,
        public int $standalonePointCount,
        public int $trackCount,
        public int $trackSampleCount,
        public int $missingCoordinateCount,
        public int $ignoredElementCount,
        public int $omittedAltitudeCount,
        public int $interpolationRequestCount,
    ) {
        foreach ([$geometryPartCount, $geometryPointCount, $standalonePointCount, $trackCount, $trackSampleCount,
            $missingCoordinateCount, $ignoredElementCount, $omittedAltitudeCount, $interpolationRequestCount] as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('Invalid KML path counts.');
            }
        }
        if ($index < 1 || $geometryPointCount < $geometryPartCount || (0 === $geometryPartCount && 0 !== $geometryPointCount)
            || $missingCoordinateCount > $trackSampleCount || $trackSampleCount - $missingCoordinateCount > $geometryPointCount
            || (0 === $trackCount && 0 !== $trackSampleCount) || $omittedAltitudeCount > $geometryPointCount) {
            throw new \InvalidArgumentException('Inconsistent KML path descriptor.');
        }
    }

    public function locator(): string
    {
        return 'kml.v2/placemark['.$this->index.']';
    }
}
