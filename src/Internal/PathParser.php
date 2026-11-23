<?php

declare(strict_types=1);

namespace Youmad\Endurance\Kml\Internal;

use Youmad\Endurance\Kml\Exception\InvalidKml;
use Youmad\Endurance\Kml\Model\AltitudeMode;
use Youmad\Endurance\Kml\Model\KmlDocument;
use Youmad\Endurance\Kml\Model\KmlPathObject;
use Youmad\Endurance\Kml\Model\KmlPathPoint;

/**
 * kml.v2 grammar; kml.v1 locators retain their original interpretation.
 *
 * @implements XmlSession<KmlPathPoint|KmlPathObject,KmlDocument>
 *
 * @internal
 */
final class PathParser implements XmlSession
{
    private const string KML = 'http://www.opengis.net/kml/2.2|';
    private const string GX = 'http://www.google.com/kml/ext/2.2|';
    private \XMLParser $xml;
    /** @var list<string> */
    private array $stack = [];
    /** @var list<KmlPathPoint|KmlPathObject> */
    private array $events = [];
    private int $index = 0;
    private int $ignored = 0;
    private int $objectIgnored = 0;
    private ?string $name = null;
    private ?string $description = null;
    private string $text = '';
    private int $parts = 0;
    private int $geometryPoints = 0;
    private int $standalonePoints = 0;
    private int $ordinal = 0;
    private int $omittedAltitude = 0;
    private int $componentPoints = 0;
    private bool $coordinatesSeen = false;
    private bool $modeSeen = false;
    private bool $gxMode = false;
    private AltitudeMode $mode = AltitudeMode::ClampToGround;
    private CoordinateTokens $tokens;
    private int $tracks = 0;
    private int $trackSamples = 0;
    private int $missing = 0;
    private int $interpolationRequests = 0;
    private ?TrackTimes $times = null;
    private int $trackPhase = 0;
    private bool $timesStarted = false;
    private int $trackCoordinates = 0;
    private int $angles = 0;
    private bool $trackPartOpen = false;
    private int $trackPartPoint = 0;
    private ?AltitudeMode $multiMode = null;
    private bool $multiStarted = false;
    private bool $interpolationSeen = false;

    public function __construct()
    {
        $this->xml = xml_parser_create_ns('UTF-8', '|');
        xml_parser_set_option($this->xml, XML_OPTION_CASE_FOLDING, false);
        xml_set_element_handler($this->xml, $this->start(...), $this->end(...));
        xml_set_character_data_handler($this->xml, $this->characters(...));
        xml_set_external_entity_ref_handler($this->xml, static function (): never { throw new InvalidKml('External KML entities are unsupported.'); });
        xml_set_default_handler($this->xml, static function (\XMLParser $parser, string $data): void {
            if (str_starts_with($data, '&')) {
                throw new InvalidKml('KML entity declarations are unsupported.');
            }
        });
    }

    /** @return list<KmlPathPoint|KmlPathObject> Bounded by the fixed input chunk. */
    public function feed(string $chunk, bool $last): array
    {
        $this->events = [];
        if (!xml_parse($this->xml, $chunk, $last)) {
            throw new InvalidKml('Invalid KML XML at line '.xml_get_current_line_number($this->xml).'.');
        }

        return $this->events;
    }

    public function document(): KmlDocument
    {
        return new KmlDocument($this->index, $this->ignored);
    }

    public function close(): void
    {
        $this->times?->close();
        $this->times = null;
        xml_set_element_handler($this->xml, null, null);
        xml_set_character_data_handler($this->xml, null);
        xml_set_external_entity_ref_handler($this->xml, null);
        xml_set_default_handler($this->xml, null);
    }

    /** @param array<string,string> $attributes */
    private function start(\XMLParser $xml, string $name, array $attributes): void
    {
        $parent = end($this->stack);
        if (count($this->stack) >= 64) {
            throw new InvalidKml('KML nesting exceeds the supported bound.');
        }
        $role = 'ignored';
        if ([] === $this->stack) {
            if (self::KML.'kml' !== $name) {
                throw new InvalidKml('Expected the KML 2.2 root namespace.');
            }
            $role = 'container';
        } elseif ('container' === $parent && in_array($name, [self::KML.'Document', self::KML.'Folder'], true)) {
            $role = 'container';
        } elseif ('container' === $parent && self::KML.'Placemark' === $name) {
            $role = 'placemark';
            ++$this->index;
            $this->name = $this->description = null;
            $this->parts = $this->geometryPoints = $this->standalonePoints = $this->ordinal = $this->objectIgnored = $this->omittedAltitude = 0;
            $this->tracks = $this->trackSamples = $this->missing = $this->interpolationRequests = 0;
        } elseif (in_array($parent, ['placemark', 'multi'], true) && self::KML.'MultiGeometry' === $name) {
            $role = 'multi';
        } elseif (in_array($parent, ['placemark', 'multi'], true) && self::GX.'MultiTrack' === $name) {
            $role = 'multitrack';
            $this->multiMode = null;
            $this->multiStarted = $this->interpolationSeen = false;
        } elseif (in_array($parent, ['placemark', 'multi', 'multitrack'], true) && self::GX.'Track' === $name) {
            $role = 'track';
            ++$this->tracks;
            if ('multitrack' === $parent) {
                $this->multiStarted = true;
            }
            $this->times = null;
            $this->trackPhase = $this->trackCoordinates = $this->angles = $this->trackPartPoint = 0;
            $this->trackPartOpen = $this->timesStarted = $this->modeSeen = false;
            $this->mode = 'multitrack' === $parent ? ($this->multiMode ?? AltitudeMode::ClampToGround) : AltitudeMode::ClampToGround;
        } elseif ('track' === $parent && self::KML.'when' === $name) {
            if (0 !== $this->trackPhase) {
                throw new InvalidKml('KML Track times must precede coordinates and angles.');
            }
            $role = 'when';
            $this->timesStarted = true;
            $this->text = '';
        } elseif ('track' === $parent && self::GX.'coord' === $name) {
            if ($this->trackPhase > 1) {
                throw new InvalidKml('KML Track coordinates must precede angles.');
            }
            $role = 'coord';
            $this->trackPhase = 1;
            $this->text = '';
        } elseif ('track' === $parent && self::GX.'angles' === $name) {
            $role = 'angles';
            $this->trackPhase = 2;
            $this->text = '';
            $this->omit();
        } elseif ('multitrack' === $parent && self::GX.'interpolate' === $name) {
            if ($this->multiStarted || $this->interpolationSeen) {
                throw new InvalidKml('MultiTrack interpolation must occur once before Tracks.');
            }
            $role = 'interpolate';
            $this->interpolationSeen = true;
            $this->text = '';
        } elseif ('multitrack' === $parent && in_array($name, [self::KML.'altitudeMode', self::GX.'altitudeMode'], true)) {
            if ($this->multiStarted || null !== $this->multiMode) {
                throw new InvalidKml('MultiTrack altitude mode must occur once before Tracks.');
            }
            $role = 'multi-mode';
            $this->gxMode = self::GX.'altitudeMode' === $name;
            $this->text = '';
        } elseif (in_array($parent, ['placemark', 'multi'], true) && in_array($name, [self::KML.'LineString', self::KML.'Point'], true)) {
            $role = self::KML.'LineString' === $name ? 'line' : 'point';
            if ('line' === $role) {
                ++$this->parts;
            }
            $this->componentPoints = 0;
            $this->coordinatesSeen = $this->modeSeen = false;
            $this->mode = AltitudeMode::ClampToGround;
        } elseif (in_array($parent, ['line', 'point'], true) && self::KML.'coordinates' === $name) {
            if ($this->coordinatesSeen) {
                throw new InvalidKml('Repeated KML coordinates.');
            }
            $role = 'coordinates';
            $this->coordinatesSeen = true;
            $this->tokens = new CoordinateTokens();
        } elseif (in_array($parent, ['line', 'point', 'track'], true) && in_array($name, [self::KML.'altitudeMode', self::GX.'altitudeMode'], true)) {
            if ($this->modeSeen || ('track' === $parent ? ($this->timesStarted || $this->trackPhase > 0) : $this->coordinatesSeen)) {
                throw new InvalidKml('KML altitude mode must occur once before positions and times.');
            }
            $role = 'mode';
            $this->modeSeen = true;
            $this->gxMode = self::GX.'altitudeMode' === $name;
            $this->text = '';
        } elseif ('placemark' === $parent && in_array($name, [self::KML.'name', self::KML.'description'], true)) {
            $role = self::KML.'name' === $name ? 'name' : 'description';
            if (null !== $this->$role) {
                throw new InvalidKml('Repeated KML metadata.');
            }
            $this->text = '';
        } elseif (in_array($parent, ['coordinates', 'mode', 'multi-mode', 'name', 'description', 'when', 'coord', 'angles', 'interpolate'], true)) {
            throw new InvalidKml('KML scalar content cannot contain child elements.');
        }
        if ('ignored' === $role) {
            $this->omit();
        }
        $this->stack[] = $role;
    }

    private function omit(): void
    {
        ++$this->ignored;
        if (in_array('placemark', $this->stack, true)) {
            ++$this->objectIgnored;
        }
    }

    private function characters(\XMLParser $xml, string $data): void
    {
        $role = end($this->stack);
        if ('coordinates' === $role) {
            $this->coordinates($data, false);
        } elseif (in_array($role, ['mode', 'multi-mode', 'name', 'description', 'when', 'coord', 'angles', 'interpolate'], true)) {
            $maximum = match ($role) {
                'when' => 64,'coord','angles' => 384,'mode','multi-mode' => 32,'interpolate' => 5,default => 4096,
            };
            if (strlen($this->text) + strlen($data) > $maximum) {
                throw new InvalidKml('KML scalar exceeds its supported bound.');
            }
            $this->text .= $data;
        } elseif ('ignored' !== $role && '' !== trim($data)) {
            throw new InvalidKml('Unexpected KML text.');
        }
    }

    private function end(\XMLParser $xml, string $name): void
    {
        $role = end($this->stack);
        if ('coordinates' === $role) {
            $this->coordinates('', true);
        } elseif ('mode' === $role || 'multi-mode' === $role) {
            $mode = AltitudeMode::tryFrom(trim($this->text));
            if (null === $mode || (!$this->gxMode && in_array($mode, [AltitudeMode::ClampToSeaFloor, AltitudeMode::RelativeToSeaFloor], true))) {
                throw new InvalidKml('Unsupported KML altitude mode.');
            }
            if ('multi-mode' === $role) {
                $this->multiMode = $mode;
            } else {
                if ('track' === $this->stack[count($this->stack) - 2] && in_array('multitrack', $this->stack, true)
                    && null !== $this->multiMode && $mode !== $this->multiMode) {
                    throw new InvalidKml('Conflicting Track and MultiTrack altitude modes are unsupported.');
                }
                $this->mode = $mode;
            }
        } elseif ('when' === $role) {
            $this->times ??= new TrackTimes();
            $this->times->append(trim($this->text));
        } elseif ('coord' === $role) {
            $this->trackPosition();
        } elseif ('angles' === $role) {
            GxValues::triple($this->text);
            ++$this->angles;
        } elseif ('interpolate' === $role) {
            $value = trim($this->text);
            if (!in_array($value, ['0', '1', 'false', 'true'], true)) {
                throw new InvalidKml('Invalid MultiTrack interpolation boolean.');
            }
            $this->interpolationRequests += in_array($value, ['1', 'true'], true) ? 1 : 0;
        } elseif ('name' === $role || 'description' === $role) {
            $this->$role = trim($this->text);
        } elseif ('line' === $role || 'point' === $role) {
            if (!$this->coordinatesSeen || ('line' === $role ? $this->componentPoints < 2 : 1 !== $this->componentPoints)) {
                throw new InvalidKml('KML LineString requires at least two coordinates; Point requires one.');
            }
        } elseif ('track' === $role) {
            $this->times?->complete();
            if (0 !== $this->angles && $this->angles !== $this->trackCoordinates) {
                throw new InvalidKml('KML Track angle array differs in length.');
            }
            $this->times?->close();
            $this->times = null;
        } elseif ('multitrack' === $role) {
            $this->multiMode = null;
        } elseif ('placemark' === $role) {
            $this->events[] = new KmlPathObject($this->index, $this->name, $this->description, $this->parts, $this->geometryPoints,
                $this->standalonePoints, $this->tracks, $this->trackSamples, $this->missing, $this->objectIgnored, $this->omittedAltitude, $this->interpolationRequests);
        }
        array_pop($this->stack);
    }

    private function coordinates(string $data, bool $last): void
    {
        $line = 'line' === $this->stack[count($this->stack) - 2];
        foreach ($this->tokens->feed($data, $last) as [$longitude,$latitude,$altitude]) {
            if (!$line && $this->componentPoints > 0) {
                throw new InvalidKml('KML Point requires exactly one coordinate.');
            }
            $this->events[] = new KmlPathPoint($this->index, ++$this->ordinal, $line ? $this->parts - 1 : null,
                $this->componentPoints++, $latitude, $longitude, $altitude, $this->mode);
            if ($line) {
                ++$this->geometryPoints;
                $this->omittedAltitude += null !== $altitude && AltitudeMode::Absolute !== $this->mode ? 1 : 0;
            } else {
                ++$this->standalonePoints;
            }
        }
    }

    private function trackPosition(): void
    {
        $when = $this->times?->next() ?? throw new InvalidKml('KML Track coordinate has no corresponding time.');
        $values = GxValues::triple($this->text);
        if (null !== $values && (abs($values[0]) > 180 || abs($values[1]) > 90)) {
            throw new InvalidKml('GX coordinates exceed geographic bounds.');
        }
        ++$this->trackSamples;
        if (null === $values) {
            ++$this->missing;
            $this->trackPartOpen = false;
            $this->events[] = new KmlPathPoint($this->index, ++$this->ordinal, null, 0, null, null, null, $this->mode, $this->tracks - 1, $this->trackCoordinates++, $when);

            return;
        }
        if (!$this->trackPartOpen) {
            ++$this->parts;
            $this->trackPartPoint = 0;
            $this->trackPartOpen = true;
        }
        [$longitude,$latitude,$altitude] = $values;
        ++$this->geometryPoints;
        $this->omittedAltitude += AltitudeMode::Absolute !== $this->mode ? 1 : 0;
        $this->events[] = new KmlPathPoint($this->index, ++$this->ordinal, $this->parts - 1, $this->trackPartPoint++, $latitude, $longitude, $altitude, $this->mode,
            $this->tracks - 1, $this->trackCoordinates++, $when);
    }
}
