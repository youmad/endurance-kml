<?php

declare(strict_types=1);

namespace Youmad\Endurance\Kml\Internal;

use Youmad\Endurance\Kml\Exception\InvalidKml;

/** @internal */
final readonly class XmlInput
{
    public function __construct(private int $maximumBytes)
    {
    }

    /**
     * @template TEvent of object
     * @template TDocument of object
     *
     * @param resource                     $stream   Caller-owned, including on failure or early disposal
     * @param XmlSession<TEvent,TDocument> $parser
     * @param (\Closure():void)|null       $progress
     *
     * @return \Generator<int,TEvent,void,TDocument>
     */
    public function events(mixed $stream, XmlSession $parser, ?\Closure $progress): \Generator
    {
        try {
            if (!is_resource($stream) || 'stream' !== get_resource_type($stream)) {
                throw new \InvalidArgumentException('KML requires an open stream.');
            }
            $buffer = '';
            $ready = false;
            $bytes = 0;
            do {
                $progress?->__invoke();
                $chunk = fread($stream, 4096);
                if (false === $chunk || ('' === $chunk && !feof($stream))) {
                    throw new \RuntimeException('KML input read failed.');
                }
                $bytes += strlen($chunk);
                if ($bytes > $this->maximumBytes) {
                    throw new InvalidKml('KML exceeds the configured byte bound.');
                }
                if (!$ready) {
                    $buffer .= $chunk;
                    if (strlen($buffer) > 65536) {
                        throw new InvalidKml('KML XML prolog exceeds the supported bound.');
                    }
                    $ready = $this->rootStarted($buffer);
                    if (!$ready) {
                        if (feof($stream)) {
                            throw new InvalidKml('Missing or oversized KML XML prolog.');
                        }
                        continue;
                    }
                    $chunk = $buffer;
                    $buffer = '';
                }
                yield from $parser->feed($chunk, feof($stream));
            } while (!feof($stream));

            return $parser->document();
        } finally {
            $parser->close();
        }
    }

    /** Reject DTDs before handing any prolog to the XML engine. UTF-8/ASCII XML only. */
    private function rootStarted(string $prefix): bool
    {
        $offset = str_starts_with($prefix, "\xEF\xBB\xBF") ? 3 : 0;
        while (true) {
            $offset += strspn($prefix, " \t\r\n", $offset);
            if (str_starts_with(substr($prefix, $offset), '<!--')) {
                $end = strpos($prefix, '-->', $offset + 4);
                if (false === $end) {
                    return false;
                }
                $offset = $end + 3;
            } elseif (str_starts_with(substr($prefix, $offset), '<?')) {
                $end = strpos($prefix, '?>', $offset + 2);
                if (false === $end) {
                    return false;
                }
                $offset = $end + 2;
            } else {
                $remaining = substr($prefix, $offset);
                if (str_starts_with($remaining, '<!DOCTYPE')) {
                    throw new InvalidKml('KML DTDs are unsupported.');
                }
                if (preg_match('/^<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?kml(?=[\x20\x09\x0D\x0A\/>])/', $remaining)) {
                    return true;
                }
                if (strlen($remaining) >= 128 || str_contains($remaining, '>') || str_contains($remaining, "\0")) {
                    throw new InvalidKml('Expected UTF-8 KML XML.');
                }

                return false;
            }
        }
    }
}
