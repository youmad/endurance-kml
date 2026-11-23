<?php

declare(strict_types=1);

/** @param Closure(bool): void $ensure */
return static function (Closure $ensure, string $autoload, string $package): void {
    $stream = fopen('php://temp', 'w+b');
    try {
        fwrite($stream, '<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><LineString><altitudeMode>absolute</altitudeMode><coordinates>179,0,0 -179,0,-2</coordinates></LineString></Placemark></kml>');
        rewind($stream);
        $points = new Endurance\Kml\PathReader()->objectPoints($stream, 'kml.v2/placemark[1]');
        $read = iterator_to_array($points, false);
        $ensure(2 === count($read) && 0.0 === $read[0]->altitude && -2.0 === $read[1]->altitude);
        $points->getReturn();
    } finally {
        fclose($stream);
    }
};
