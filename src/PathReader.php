<?php

declare(strict_types=1);

namespace Youmad\Endurance\Kml;

use Youmad\Endurance\Kml\Exception\KmlObjectNotFound;
use Youmad\Endurance\Kml\Internal\PathParser;
use Youmad\Endurance\Kml\Internal\XmlInput;
use Youmad\Endurance\Kml\Model\KmlDocument;
use Youmad\Endurance\Kml\Model\KmlPathObject;
use Youmad\Endurance\Kml\Model\KmlPathPoint;
use Youmad\Endurance\Kml\Model\KmlPathReadResult;

final readonly class PathReader
{
    public function __construct(private int $maximumBytes = 268435456)
    {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('KML byte bound must be positive.');
        }
    }

    /**
     * @param resource                $stream   Caller-owned; non-seekable input is supported
     * @param (\Closure(): void)|null $progress
     *
     * @return \Generator<int,KmlPathObject,void,KmlDocument>
     */
    public function objects(mixed $stream, ?\Closure $progress = null): \Generator
    {
        $events = $this->events($stream, $progress);
        foreach ($events as $event) {
            if ($event instanceof KmlPathObject) {
                yield $event;
            }
        }

        return $events->getReturn();
    }

    /**
     * @param resource                $stream
     * @param (\Closure(): void)|null $progress
     *
     * @return \Generator<int,KmlPathPoint,void,KmlPathReadResult>
     */
    public function objectPoints(mixed $stream, string $locator, ?\Closure $progress = null): \Generator
    {
        if (!preg_match('/^kml\.v2\/placemark\[([1-9][0-9]*)\]$/D', $locator, $match)
            || strlen($match[1]) > strlen((string) PHP_INT_MAX)
            || (strlen($match[1]) === strlen((string) PHP_INT_MAX) && strcmp($match[1], (string) PHP_INT_MAX) > 0)) {
            throw new \InvalidArgumentException('Expected a canonical kml.v2 Placemark locator.');
        }
        $index = (int) $match[1];
        $found = null;
        $events = $this->events($stream, $progress);
        foreach ($events as $event) {
            if (($event instanceof KmlPathPoint ? $event->placemarkIndex : $event->index) !== $index) {
                continue;
            }
            if ($event instanceof KmlPathPoint) {
                yield $event;
            } else {
                $found = $event;
            }
        }
        $document = $events->getReturn();
        if (null === $found) {
            throw new KmlObjectNotFound('KML Placemark is absent from the source.');
        }

        return new KmlPathReadResult($found, $document);
    }

    /**
     * @param resource               $stream
     * @param (\Closure():void)|null $progress
     *
     * @return \Generator<int,KmlPathPoint|KmlPathObject,void,KmlDocument>
     */
    private function events(mixed $stream, ?\Closure $progress): \Generator
    {
        return new XmlInput($this->maximumBytes)->events($stream, new PathParser(), $progress);
    }
}
