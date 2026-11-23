<?php

declare(strict_types=1);

namespace Endurance\Kml\Model;

final readonly class KmlObject
{
    public function __construct(
        public int $index,
        public ?string $name,
        public ?string $description,
        public int $lineCount,
        public int $linePointCount,
        public int $standalonePointCount,
        public int $ignoredElementCount,
        public int $omittedAltitudeCount,
    ) {
        foreach ([$lineCount, $linePointCount, $standalonePointCount, $ignoredElementCount, $omittedAltitudeCount] as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('Invalid KML object counts.');
            }
        }
        if ($index < 1 || $linePointCount < 2 * $lineCount || (0 === $lineCount && 0 !== $linePointCount)
            || $omittedAltitudeCount > $linePointCount) {
            throw new \InvalidArgumentException('Invalid KML object descriptor.');
        }
    }

    public function locator(): string
    {
        return 'kml.v1/placemark['.$this->index.']';
    }
}
