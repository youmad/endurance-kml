<?php

declare(strict_types=1);

namespace Endurance\Kml\Tests;

use Endurance\Kml\Exception\InvalidKml;
use Endurance\Kml\Exception\KmlObjectNotFound;
use Endurance\Kml\Model\AltitudeMode;
use Endurance\Kml\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReaderTest extends TestCase
{
    /** @return resource */
    private function stream(string $body, bool $wrap = true): mixed
    {
        $s = fopen('php://temp', 'w+b');
        self::assertIsResource($s);
        fwrite($s, $wrap ? '<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2">'.$body.'</kml>' : $body);
        rewind($s);

        return $s;
    }

    public function testWholeSourceSelectionKeepsNestedPartsLateMetadataAndUnattachedPoint(): void
    {
        $s = $this->stream('<Document><Folder><Placemark><MultiGeometry>'
            .'<LineString><altitudeMode>absolute</altitudeMode><coordinates>-180,-90,0 180,90,-2</coordinates></LineString>'
            .'<MultiGeometry><Point><coordinates>12,34,7</coordinates></Point>'
            .'<LineString><gx:altitudeMode>relativeToGround</gx:altitudeMode><coordinates>1,2,30 3,4</coordinates></LineString></MultiGeometry>'
            .'</MultiGeometry><name>Место &amp; path</name><description><![CDATA[<b>Plain data</b>]]></description></Placemark>'
            .'<Placemark><Point><coordinates>20,10</coordinates></Point></Placemark></Folder></Document>');
        try {
            $g = new Reader()->objectPoints($s, 'kml.v1/placemark[1]');
            $points = iterator_to_array($g, false);
            $result = $g->getReturn();
            self::assertSame([0, 0, null, 1, 1], array_column($points, 'partIndex'));
            self::assertSame([0, 1, 0, 0, 1], array_column($points, 'pointIndex'));
            self::assertSame([1, 2, 3, 4, 5], array_column($points, 'ordinal'));
            self::assertSame([-90.0, 90.0, 34.0, 2.0, 4.0], array_column($points, 'latitude'));
            self::assertSame([0.0, -2.0, 7.0, 30.0, null], array_column($points, 'altitude'));
            self::assertSame(AltitudeMode::RelativeToGround, $points[3]->altitudeMode);
            self::assertSame('Место & path', $result->object->name);
            self::assertSame('<b>Plain data</b>', $result->object->description);
            self::assertSame([2, 4, 1, 1], [$result->object->lineCount, $result->object->linePointCount, $result->object->standalonePointCount, $result->object->omittedAltitudeCount]);
            self::assertSame(2, $result->document->placemarkCount);
            self::assertTrue(is_resource($s));
        } finally {
            fclose($s);
        }
    }

    public function testNamespaceAliasesAndIgnoredSubtreesDoNotImpersonateCoreGeometry(): void
    {
        $s = $this->stream('<q:kml xmlns:q="http://www.opengis.net/kml/2.2" xmlns:x="urn:foreign">'
            .'<x:Document><q:Placemark><q:Point><q:coordinates>0,0</q:coordinates></q:Point></q:Placemark></x:Document>'
            .'<q:Placemark><q:Style><q:LineString><q:coordinates>bad</q:coordinates></q:LineString></q:Style>'
            .'<x:LineString><q:coordinates>bad</q:coordinates></x:LineString>'
            .'<q:MultiGeometry/><q:Polygon><q:coordinates>not a supported path</q:coordinates></q:Polygon></q:Placemark></q:kml>', false);
        try {
            $g = new Reader()->objects($s);
            $objects = iterator_to_array($g, false);
            self::assertCount(1, $objects);
            self::assertSame(0, $objects[0]->linePointCount);
            self::assertGreaterThan(0, $objects[0]->ignoredElementCount);
            self::assertSame(1, $g->getReturn()->placemarkCount);
        } finally {
            fclose($s);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalid(): iterable
    {
        yield 'wrong namespace' => ['<kml xmlns="urn:wrong"/>'];
        yield 'doctype' => ['<!DOCTYPE kml [<!ENTITY a "boom">]><kml xmlns="http://www.opengis.net/kml/2.2">&a;</kml>'];
        yield 'external doctype' => ['<!DOCTYPE kml SYSTEM "file:///missing-never-open"><kml xmlns="http://www.opengis.net/kml/2.2"/>'];
        yield 'empty line' => ['<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><LineString><coordinates/></LineString></Placemark></kml>'];
        yield 'one point line' => ['<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><LineString><coordinates>1,2</coordinates></LineString></Placemark></kml>'];
        yield 'two point Point' => ['<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><Point><coordinates>1,2 3,4</coordinates></Point></Placemark></kml>'];
        foreach (['1, 2', '1,2,', '181,2', '1,-91', 'INF,2', '1,NaN', '1e999,2', '1,2,3,4', str_repeat('1', 129).',2'] as $coords) {
            yield $coords => ['<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><LineString><coordinates>'.$coords.' 3,4</coordinates></LineString></Placemark></kml>'];
        }
        yield 'late mode' => ['<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><LineString><coordinates>1,2 3,4</coordinates><altitudeMode>absolute</altitudeMode></LineString></Placemark></kml>'];
        yield 'duplicate mode' => ['<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><LineString><altitudeMode>absolute</altitudeMode><altitudeMode>absolute</altitudeMode><coordinates>1,2 3,4</coordinates></LineString></Placemark></kml>'];
        yield 'invalid mode' => ['<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><Point><altitudeMode>clampToSeaFloor</altitudeMode><coordinates>1,2</coordinates></Point></Placemark></kml>'];
        yield 'nested scalar' => ['<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><name><b>not CDATA</b></name></Placemark></kml>'];
        yield 'oversized metadata' => ['<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><name>'.str_repeat('a', 4097).'</name></Placemark></kml>'];
        yield 'oversized prolog' => ['<!--'.str_repeat(' ', 70000).'--><kml xmlns="http://www.opengis.net/kml/2.2"/>'];
        yield 'depth' => ['<kml xmlns="http://www.opengis.net/kml/2.2">'.str_repeat('<Folder>', 65).str_repeat('</Folder>', 65).'</kml>'];
        yield 'tail' => ['<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark/></kml><unfinished'];
        yield 'UTF16' => ["\xFF\xFE<\0k\0m\0l\0/\0>\0"];
    }

    #[DataProvider('invalid')]
    public function testRejectsUnsupportedOrMalformedInputAndKeepsCallerStream(string $xml): void
    {
        $s = $this->stream($xml, false);
        try {
            $this->expectException(InvalidKml::class);
            iterator_to_array(new Reader()->objects($s), false);
        } finally {
            self::assertTrue(is_resource($s));
            fclose($s);
        }
    }

    public function testMissingLocatorIsDifferentFromAnEmptyPlacemark(): void
    {
        $s = $this->stream('<Placemark/>');
        try {
            $g = new Reader()->objectPoints($s, 'kml.v1/placemark[1]');
            self::assertSame([], iterator_to_array($g, false));
            self::assertSame(0, $g->getReturn()->object->lineCount);
            rewind($s);
            $this->expectException(KmlObjectNotFound::class);
            iterator_to_array(new Reader()->objectPoints($s, 'kml.v1/placemark[2]'));
        } finally {
            fclose($s);
        }
    }

    public function testSelectedDataIsNotValidatedBeforeCorruptSiblingTail(): void
    {
        $s = $this->stream('<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><LineString><coordinates>1,2 3,4</coordinates></LineString></Placemark>'
            .str_repeat(' ', 5000).'<Placemark><broken></Placemark></kml>', false);
        try {
            $g = new Reader()->objectPoints($s, 'kml.v1/placemark[1]');
            $g->rewind();
            self::assertSame(1.0, $g->current()->longitude);
            $this->expectException(InvalidKml::class);
            iterator_to_array($g, false);
        } finally {
            fclose($s);
        }
    }

    public function testByteBoundProgressCancellationAndNonseekableOwnership(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        fwrite($pair[0], '<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark/></kml>');
        fclose($pair[0]);
        try {
            $g = new Reader()->objects($pair[1]);
            self::assertCount(1, iterator_to_array($g, false));
            self::assertSame(1, $g->getReturn()->placemarkCount);
        } finally {
            fclose($pair[1]);
        }
        $s = $this->stream('<Placemark/>');
        try {
            try {
                iterator_to_array(new Reader(10)->objects($s));
                self::fail('Expected byte bound.');
            } catch (InvalidKml) {
            }
            rewind($s);
            $failure = new \RuntimeException('cancelled cooperatively');
            try {
                iterator_to_array(new Reader()->objects($s, static function () use ($failure): never {throw $failure; }));
                self::fail('Expected progress error.');
            } catch (\RuntimeException $e) {
                self::assertSame($failure, $e);
            }
        } finally {
            fclose($s);
        }
    }
}
