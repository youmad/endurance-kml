<?php

declare(strict_types=1);

namespace Youmad\Endurance\Kml\Model;

final readonly class KmlDocument
{
    public function __construct(public int $placemarkCount, public int $ignoredElementCount)
    {
    }
}
