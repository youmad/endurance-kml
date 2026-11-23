<?php

declare(strict_types=1);

namespace Endurance\Kml\Model;

final readonly class KmlPathReadResult
{
    public function __construct(public KmlPathObject $object, public KmlDocument $document)
    {
    }
}
