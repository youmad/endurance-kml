<?php

declare(strict_types=1);

namespace Endurance\Kml\Model;

final readonly class KmlObjectReadResult
{
    public function __construct(public KmlObject $object, public KmlDocument $document)
    {
        if ($object->index > $document->placemarkCount) {
            throw new \InvalidArgumentException('KML object is absent from document counts.');
        }
    }
}
