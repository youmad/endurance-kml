<?php

declare(strict_types=1);

namespace Endurance\Kml\Internal;

/**
 * One versioned parse session; discard provisional events if completion fails.
 *
 * @internal
 *
 * @template-covariant TEvent of object
 * @template-covariant TDocument of object
 */
interface XmlSession
{
    /** @return list<TEvent> */
    public function feed(string $chunk, bool $last): array;

    /** @return TDocument */
    public function document(): object;

    public function close(): void;
}
