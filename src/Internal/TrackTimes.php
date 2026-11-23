<?php

declare(strict_types=1);

namespace Youmad\Endurance\Kml\Internal;

use Youmad\Endurance\Kml\Exception\InvalidKml;

/** GX timestamps precede coordinates; queue them on disk to bound memory. */
/** @internal */
final class TrackTimes
{
    /** @var resource|null */
    private mixed $stream;
    private int $written = 0;
    private int $read = 0;
    private bool $reading = false;

    public function __construct()
    {
        $stream = tmpfile();
        if (false === $stream) {
            throw new \RuntimeException('Cannot create KML timestamp queue.');
        }
        $this->stream = $stream;
    }

    public function append(string $when): void
    {
        if ($this->reading) {
            throw new InvalidKml('KML Track times must precede coordinates.');
        }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,32})?(Z|([+-])(\d{2}):(\d{2}))$/D', $when, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59
            || ('Z' !== $m[7] && ((int) $m[9] > 14 || (int) $m[10] > 59 || (14 === (int) $m[9] && '00' !== $m[10])))) {
            throw new InvalidKml('KML Track requires complete zoned timestamps in the supported range.');
        }
        $line = $when."\n";
        if (strlen($line) !== fwrite($this->open(), $line)) {
            throw new \RuntimeException('Cannot stage KML timestamps.');
        }
        ++$this->written;
    }

    public function next(): string
    {
        if (!$this->reading) {
            if (!rewind($this->open())) {
                throw new \RuntimeException('Cannot rewind KML timestamps.');
            }
            $this->reading = true;
        }
        if ($this->read === $this->written) {
            throw new InvalidKml('KML Track has more coordinates than times.');
        }
        $line = fgets($this->open());
        if (false === $line || !str_ends_with($line, "\n")) {
            throw new \RuntimeException('Incomplete KML timestamp queue.');
        }
        ++$this->read;

        return substr($line, 0, -1);
    }

    public function complete(): void
    {
        if ($this->read !== $this->written) {
            throw new InvalidKml('KML Track time and coordinate arrays differ in length.');
        }
    }

    /** @return resource */
    private function open(): mixed
    {
        if (!is_resource($this->stream)) {
            throw new \LogicException('KML timestamp queue is closed.');
        }

        return $this->stream;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    private function __clone()
    {
    }

    /** @return array<string,mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('KML timestamp queue is a local resource.');
    }

    public function __destruct()
    {
        $this->close();
    }
}
