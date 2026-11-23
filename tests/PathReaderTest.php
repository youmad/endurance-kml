<?php

declare(strict_types=1);

namespace Youmad\Endurance\Kml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Kml\Exception\InvalidKml;
use Youmad\Endurance\Kml\Exception\KmlObjectNotFound;
use Youmad\Endurance\Kml\Model\AltitudeMode;
use Youmad\Endurance\Kml\PathReader;
use Youmad\Endurance\Kml\Reader;

final class PathReaderTest extends TestCase
{
    private const string TIME = '2026-11-06T10:00:00Z';

    /** @return resource */
    private function stream(string $body): mixed
    {
        $s = tmpfile();
        self::assertIsResource($s);
        fwrite($s, '<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2">'.$body.'</kml>');
        rewind($s);

        return $s;
    }

    public function testMixedPathsPreservePositionalTimesGapsTrackBoundariesAndLateMetadata(): void
    {
        $s = fopen(__DIR__.'/Fixture/mixed-path-v2.kml', 'rb');
        self::assertIsResource($s);
        try {
            $g = new PathReader()->objectPoints($s, 'kml.v2/placemark[1]');
            $p = iterator_to_array($g, false);
            $r = $g->getReturn();
            self::assertSame(range(1, 10), array_column($p, 'ordinal'));
            self::assertSame([0, 0, 1, null, 2, 2, 3, null, 4, 4], array_column($p, 'partIndex'));
            self::assertSame([0, 1, 0, 0, 0, 1, 0, 0, 0, 1], array_column($p, 'pointIndex'));
            self::assertSame([null, null, 0, 0, 0, 0, 1, null, null, null], array_column($p, 'trackIndex'));
            self::assertSame([null, null, 0, 1, 2, 3, 0, null, null, null], array_column($p, 'trackSampleIndex'));
            self::assertSame([self::TIME, self::TIME, '2026-11-06T09:59:59.123456789+00:00', '2026-11-06T10:00:03Z', '2026-11-06T10:00:04Z'], array_slice(array_column($p, 'when'), 2, 5));
            self::assertNull($p[3]->latitude);
            self::assertNull($p[3]->longitude);
            self::assertNull($p[3]->altitude);
            self::assertSame(AltitudeMode::Absolute, $p[2]->altitudeMode);
            self::assertSame(-5.0, $p[6]->altitude);
            self::assertSame(0.0, $p[0]->altitude);
            self::assertSame('Late loop', $r->object->name);
            self::assertSame('<b>Source text</b>', $r->object->description);
            self::assertSame([5, 8, 1, 2, 5, 1, 0, 2, 1], [$r->object->geometryPartCount, $r->object->geometryPointCount, $r->object->standalonePointCount,
                $r->object->trackCount, $r->object->trackSampleCount, $r->object->missingCoordinateCount, $r->object->ignoredElementCount,
                $r->object->omittedAltitudeCount, $r->object->interpolationRequestCount]);
            self::assertSame(2, $r->document->placemarkCount);
            self::assertTrue(is_resource($s));
        } finally {
            fclose($s);
        }
    }

    public function testLegacyReaderKeepsItsLocatorAndOmissionContractEvenForMalformedGxContent(): void
    {
        $s = $this->stream('<Placemark><MultiGeometry><LineString><coordinates>1,2 3,4</coordinates></LineString>'
            .'<gx:Track><when>not-a-time</when><gx:coord>not coordinates</gx:coord></gx:Track></MultiGeometry></Placemark>');
        try {
            $old = new Reader()->objectPoints($s, 'kml.v1/placemark[1]');
            self::assertCount(2, iterator_to_array($old, false));
            self::assertSame('kml.v1/placemark[1]', $old->getReturn()->object->locator());
            self::assertSame(3, $old->getReturn()->object->ignoredElementCount);
            rewind($s);
            $this->expectException(InvalidKml::class);
            iterator_to_array(new PathReader()->objects($s));
        } finally {
            fclose($s);
        }
    }

    public function testEmptyAndMissingPositionsAreDifferentFromAMissingPlacemark(): void
    {
        $s = $this->stream('<Placemark><gx:MultiTrack><gx:Track/><gx:Track><when>'.self::TIME.'</when><gx:coord/></gx:Track></gx:MultiTrack></Placemark>');
        try {
            $g = new PathReader()->objectPoints($s, 'kml.v2/placemark[1]');
            $p = iterator_to_array($g, false);
            $o = $g->getReturn()->object;
            self::assertCount(1, $p);
            self::assertSame(1, $p[0]->trackIndex);
            self::assertSame(0, $o->geometryPointCount);
            self::assertSame(2, $o->trackCount);
            self::assertSame(1, $o->missingCoordinateCount);
            rewind($s);
            $this->expectException(KmlObjectNotFound::class);
            iterator_to_array(new PathReader()->objectPoints($s, 'kml.v2/placemark[2]'));
        } finally {
            fclose($s);
        }
    }

    public function testAnglesAreCountedButCannotAlterPositionPairing(): void
    {
        $s = $this->stream('<Placemark><gx:Track><when>'.self::TIME.'</when><when>'.self::TIME.'</when>'
            .'<gx:coord>180 90 -2</gx:coord><gx:coord>-180 -90 0</gx:coord><gx:angles/><gx:angles>1 2 -3</gx:angles></gx:Track></Placemark>');
        try {
            $g = new PathReader()->objectPoints($s, 'kml.v2/placemark[1]');
            $p = iterator_to_array($g, false);
            self::assertSame([180.0, -180.0], array_column($p, 'longitude'));
            self::assertSame(2, $g->getReturn()->object->ignoredElementCount);
            self::assertSame(2, $g->getReturn()->object->omittedAltitudeCount);
        } finally {
            fclose($s);
        }
    }

    public function testForeignNamespaceAndIgnoredSubtreesCannotImpersonateTracks(): void
    {
        $s = $this->stream('<Placemark><Style><gx:Track><when>bad</when><gx:coord>bad</gx:coord></gx:Track></Style>'
            .'<x:Track xmlns:x="urn:foreign"><gx:coord>bad</gx:coord></x:Track><gx:MultiTrack/></Placemark>');
        try {
            $g = new PathReader()->objects($s);
            $o = iterator_to_array($g, false)[0];
            self::assertSame(0, $o->trackCount);
            self::assertSame(0, $o->geometryPointCount);
            self::assertGreaterThan(0, $o->ignoredElementCount);
        } finally {
            fclose($s);
        }
    }

    public function testNamespaceAliasesAndSeaFloorModesRemainSourceFacts(): void
    {
        $xml = '<q:kml xmlns:q="http://www.opengis.net/kml/2.2" xmlns:g="http://www.google.com/kml/ext/2.2"><q:Placemark>'
            .'<g:MultiTrack><g:altitudeMode>relativeToSeaFloor</g:altitudeMode><g:interpolate>false</g:interpolate><g:Track>'
            .'<q:when>2026-11-06T10:00:00+14:00</q:when><g:coord>0 0 -2</g:coord></g:Track></g:MultiTrack></q:Placemark></q:kml>';
        $s = tmpfile();
        self::assertIsResource($s);
        fwrite($s, $xml);
        rewind($s);
        try {
            $g = new PathReader()->objectPoints($s, 'kml.v2/placemark[1]');
            $p = iterator_to_array($g, false);
            self::assertSame(AltitudeMode::RelativeToSeaFloor, $p[0]->altitudeMode);
            self::assertSame(0, $g->getReturn()->object->interpolationRequestCount);
        } finally {
            fclose($s);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidContent(): iterable
    {
        $w = '<when>'.self::TIME.'</when>';
        $c = '<gx:coord>1 2 3</gx:coord>';
        foreach (['too many coordinates' => $c, 'too few coordinates' => $w, 'empty time' => '<when/>'.$c,
            'interleaved arrays' => $w.$c.$w.$c, 'coordinates after angles' => $w.'<gx:angles/>'.$c,
            'angle cardinality' => $w.$c.'<gx:angles/><gx:angles/>', 'bad angle' => $w.$c.'<gx:angles>NaN 0 0</gx:angles>',
            'nested scalar' => '<when><a/></when>', 'late altitude' => $w.'<altitudeMode>absolute</altitudeMode>'.$c] as $label => $body) {
            yield $label => ['<Placemark><gx:Track>'.$body.'</gx:Track></Placemark>'];
        }
        foreach (['1,2,3', '1 2', '1 2 3 4', '181 0 0', '0 -91 0', 'NaN 0 0', '1e999 0 0', str_repeat('1', 129).' 2 3'] as $value) {
            yield 'coordinates '.$value => ['<Placemark><gx:Track>'.$w.'<gx:coord>'.$value.'</gx:coord></gx:Track></Placemark>'];
        }
        foreach (['2026-02-30T00:00:00Z', '2026-11-06T24:00:00Z', '2026-11-06T10:00:60Z', '2026-11-06T10:00:00+14:01',
            '2026-11-06T10:00:00+15:00', '2026-11-06T10:00:00', '2026-11-06', 'not-a-time', '0000-01-01T00:00:00Z', str_repeat('1', 65)] as $time) {
            yield 'time '.$time => ['<Placemark><gx:Track><when>'.$time.'</when>'.$c.'</gx:Track></Placemark>'];
        }
        yield 'conflicting altitude' => ['<Placemark><gx:MultiTrack><altitudeMode>absolute</altitudeMode><gx:Track><altitudeMode>relativeToGround</altitudeMode>'.$w.$c.'</gx:Track></gx:MultiTrack></Placemark>'];
        yield 'late multi altitude' => ['<Placemark><gx:MultiTrack><gx:Track/><altitudeMode>absolute</altitudeMode></gx:MultiTrack></Placemark>'];
        yield 'duplicate interpolation' => ['<Placemark><gx:MultiTrack><gx:interpolate>0</gx:interpolate><gx:interpolate>1</gx:interpolate></gx:MultiTrack></Placemark>'];
        yield 'invalid interpolation' => ['<Placemark><gx:MultiTrack><gx:interpolate>yes</gx:interpolate></gx:MultiTrack></Placemark>'];
        yield 'two Point positions' => ['<Placemark><Point><coordinates>1,2 3,4</coordinates></Point></Placemark>'];
        yield 'invalid unselected sibling' => ['<Placemark><LineString><coordinates>1,2 3,4</coordinates></LineString></Placemark><Placemark><gx:Track>'.$w.'</gx:Track></Placemark>'];
    }

    #[DataProvider('invalidContent')]
    public function testRejectsMalformedOrUnsupportedContentAndKeepsCallerInput(string $body): void
    {
        $s = $this->stream($body);
        try {
            $this->expectException(InvalidKml::class);
            iterator_to_array(new PathReader()->objects($s), false);
        } finally {
            self::assertTrue(is_resource($s));
            fclose($s);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidLocators(): iterable
    {
        foreach (['kml.v1/placemark[1]', 'kml.v2/placemark[0]', 'kml.v2/placemark[01]',
            'kml.v2/placemark['.PHP_INT_MAX.'0]', 'kml.v2/placemark[1]/other', 'KML.v2/placemark[1]'] as $locator) {
            yield $locator => [$locator];
        }
    }

    #[DataProvider('invalidLocators')]
    public function testNewGrammarCannotConsumeLegacyOrNoncanonicalLocators(string $locator): void
    {
        $s = $this->stream('<Placemark/>');
        try {
            $this->expectException(\InvalidArgumentException::class);
            iterator_to_array(new PathReader()->objectPoints($s, $locator));
        } finally {
            fclose($s);
        }
    }

    public function testEarlyDisposalAndProgressFailureCloseTheQueueButKeepCallerInput(): void
    {
        $s = $this->stream('<Placemark><gx:Track>'.str_repeat('<when>'.self::TIME.'</when>', 500).str_repeat('<gx:coord>1 2 3</gx:coord>', 500).'</gx:Track></Placemark>');
        try {
            $before = count(get_resources('stream'));
            $g = new PathReader()->objectPoints($s, 'kml.v2/placemark[1]');
            $g->rewind();
            self::assertGreaterThan($before, count(get_resources('stream')));
            unset($g);
            gc_collect_cycles();
            self::assertSame($before, count(get_resources('stream')));
            self::assertTrue(is_resource($s));
            rewind($s);
            $failure = new \RuntimeException('lease lost');
            $calls = 0;
            try {
                iterator_to_array(new PathReader()->objects($s, static function () use (&$calls, $failure): void { if (3 === ++$calls) { throw $failure; } }));
                self::fail('Progress cancellation was ignored.');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            gc_collect_cycles();
            self::assertSame($before, count(get_resources('stream')));
        } finally {
            fclose($s);
        }
    }

    public function testNonseekableInputIsSupportedAndTailCompletionStillRequired(): void
    {
        $bytes = '<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2"><Placemark><gx:Track>'
            .'<when>'.self::TIME.'</when><gx:coord>1 2 3</gx:coord></gx:Track></Placemark></kml>';
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        fwrite($pair[0], $bytes);
        fclose($pair[0]);
        try {
            $g = new PathReader()->objects($pair[1]);
            self::assertCount(1, iterator_to_array($g, false));
            self::assertSame(1, $g->getReturn()->placemarkCount);
        } finally {
            fclose($pair[1]);
        }
        $s = tmpfile();
        self::assertIsResource($s);
        fwrite($s, $bytes.str_repeat(' ', 20000).'<bad>');
        rewind($s);
        try {
            $g = new PathReader()->objectPoints($s, 'kml.v2/placemark[1]');
            $g->rewind();
            self::assertSame(1.0, $g->current()->longitude);
            $this->expectException(InvalidKml::class);
            iterator_to_array($g, false);
        } finally {
            fclose($s);
        }
    }

    public function testNewReaderSharesTheExistingPrologAndByteBounds(): void
    {
        foreach (['<!DOCTYPE kml [<!ENTITY x "bad">]><kml xmlns="http://www.opengis.net/kml/2.2"/>', '<kml xmlns="urn:wrong"/>'] as $bytes) {
            $s = tmpfile();
            self::assertIsResource($s);
            fwrite($s, $bytes);
            rewind($s);
            try {
                iterator_to_array(new PathReader()->objects($s));
                self::fail('Invalid XML boundary was accepted.');
            } catch (InvalidKml) {
                self::assertTrue(is_resource($s));
            } finally {
                fclose($s);
            }
        }
        $s = $this->stream('<Placemark/>');
        try {
            $this->expectException(InvalidKml::class);
            iterator_to_array(new PathReader(10)->objects($s));
        } finally {
            fclose($s);
        }
    }
}
