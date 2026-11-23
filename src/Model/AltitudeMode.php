<?php

declare(strict_types=1);

namespace Youmad\Endurance\Kml\Model;

enum AltitudeMode: string
{
    case ClampToGround = 'clampToGround';
    case RelativeToGround = 'relativeToGround';
    case Absolute = 'absolute';
    case ClampToSeaFloor = 'clampToSeaFloor';
    case RelativeToSeaFloor = 'relativeToSeaFloor';
}
