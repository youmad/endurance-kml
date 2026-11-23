<?php

declare(strict_types=1);

namespace Youmad\Endurance\Kml\Tests;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Kml\Internal\CoordinateTokens;

final class CoordinateTokensTest extends TestCase
{
    public function testEverySplitAndOneByteChunksPreserveDecimalExponentAndWhitespace(): void
    {
        $text = " -1.25e+1,+2.5,-0\r\n 180,-90\t0.25,1e-2,3 ";
        $expected = [[-12.5, 2.5, -0.0], [180.0, -90.0, null], [0.25, 0.01, 3.0]];
        for ($i = 0; $i <= strlen($text); ++$i) {
            $tokens = new CoordinateTokens();
            self::assertSame($expected, [...$tokens->feed(substr($text, 0, $i)), ...$tokens->feed(substr($text, $i), true)]);
        }
        $tokens = new CoordinateTokens();
        $all = [];
        foreach (str_split($text) as $byte) {
            array_push($all, ...$tokens->feed($byte));
        }
        array_push($all, ...$tokens->feed('', true));
        self::assertSame($expected, $all);
    }
}
