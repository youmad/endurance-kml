<?php

declare(strict_types=1);

namespace Youmad\Endurance\Kml\Internal;

use Youmad\Endurance\Kml\Exception\InvalidKml;
use Youmad\Endurance\Kml\Model\AltitudeMode;
use Youmad\Endurance\Kml\Model\KmlDocument;
use Youmad\Endurance\Kml\Model\KmlObject;
use Youmad\Endurance\Kml\Model\KmlPoint;

/**
 * The installed kml.v1 grammar. New accepted geometry belongs to PathParser.
 *
 * @implements XmlSession<KmlPoint|KmlObject,KmlDocument>
 *
 * @internal
 */
final class DocumentParser implements XmlSession
{
    private const string KML = 'http://www.opengis.net/kml/2.2|';
    private const string GX = 'http://www.google.com/kml/ext/2.2|';
    private \XMLParser $xml;
    /** @var list<string> */
    private array $stack = [];
    /** @var list<KmlPoint|KmlObject> */
    private array $events = [];
    private int $index = 0;
    private int $ignored = 0;
    private int $objectIgnored = 0;
    private ?string $name = null;
    private ?string $description = null;
    private string $text = '';
    private int $lines = 0;
    private int $linePoints = 0;
    private int $points = 0;
    private int $ordinal = 0;
    private int $omittedAltitude = 0;
    private int $geometryPoints = 0;
    private bool $coordinatesSeen = false;
    private bool $modeSeen = false;
    private bool $gxMode = false;
    private AltitudeMode $mode = AltitudeMode::ClampToGround;
    private CoordinateTokens $tokens;

    public function __construct()
    {
        $this->xml = xml_parser_create_ns('UTF-8', '|');
        xml_parser_set_option($this->xml, XML_OPTION_CASE_FOLDING, false);
        xml_set_element_handler($this->xml, $this->start(...), $this->end(...));
        xml_set_character_data_handler($this->xml, $this->characters(...));
        xml_set_external_entity_ref_handler($this->xml, static function (): never {
            throw new InvalidKml('External KML entities are unsupported.');
        });
        xml_set_default_handler($this->xml, static function (\XMLParser $parser, string $data): void {
            if (str_starts_with($data, '&')) {
                throw new InvalidKml('KML entity declarations are unsupported.');
            }
        });
    }

    /** @return list<KmlPoint|KmlObject> Bounded by the caller's fixed-size input chunk. */
    public function feed(string $chunk, bool $last): array
    {
        $this->events = [];
        if (!xml_parse($this->xml, $chunk, $last)) {
            throw new InvalidKml('Invalid KML XML at line '.xml_get_current_line_number($this->xml).'.');
        }

        return $this->events;
    }

    /** Break callback cycles when the consumer stops early or parsing fails. */
    public function close(): void
    {
        xml_set_element_handler($this->xml, null, null);
        xml_set_character_data_handler($this->xml, null);
        xml_set_external_entity_ref_handler($this->xml, null);
        xml_set_default_handler($this->xml, null);
    }

    public function document(): KmlDocument
    {
        return new KmlDocument($this->index, $this->ignored);
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
            $this->lines = $this->linePoints = $this->points = $this->ordinal = $this->objectIgnored = $this->omittedAltitude = 0;
        } elseif (in_array($parent, ['placemark', 'multi'], true) && self::KML.'MultiGeometry' === $name) {
            $role = 'multi';
        } elseif (in_array($parent, ['placemark', 'multi'], true) && in_array($name, [self::KML.'LineString', self::KML.'Point'], true)) {
            $role = self::KML.'LineString' === $name ? 'line' : 'point';
            if ('line' === $role) {
                ++$this->lines;
            }
            $this->geometryPoints = 0;
            $this->coordinatesSeen = $this->modeSeen = false;
            $this->mode = AltitudeMode::ClampToGround;
        } elseif (in_array($parent, ['line', 'point'], true) && self::KML.'coordinates' === $name) {
            if ($this->coordinatesSeen) {
                throw new InvalidKml('Repeated KML coordinates.');
            }
            $role = 'coordinates';
            $this->coordinatesSeen = true;
            $this->tokens = new CoordinateTokens();
        } elseif (in_array($parent, ['line', 'point'], true) && in_array($name, [self::KML.'altitudeMode', self::GX.'altitudeMode'], true)) {
            if ($this->modeSeen || $this->coordinatesSeen) {
                throw new InvalidKml('KML altitude mode must occur once before coordinates.');
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
        } elseif (in_array($parent, ['coordinates', 'mode', 'name', 'description'], true)) {
            throw new InvalidKml('KML scalar content cannot contain child elements.');
        }
        if ('ignored' === $role) {
            ++$this->ignored;
            if (in_array('placemark', $this->stack, true)) {
                ++$this->objectIgnored;
            }
        }
        $this->stack[] = $role;
    }

    private function characters(\XMLParser $xml, string $data): void
    {
        $role = end($this->stack);
        if ('coordinates' === $role) {
            $this->coordinates($data, false);
        } elseif (in_array($role, ['mode', 'name', 'description'], true)) {
            if (strlen($this->text) + strlen($data) > 4096) {
                throw new InvalidKml('KML metadata exceeds the supported bound.');
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
        } elseif ('mode' === $role) {
            $mode = AltitudeMode::tryFrom(trim($this->text));
            if (null === $mode || (!$this->gxMode && in_array($mode, [AltitudeMode::ClampToSeaFloor, AltitudeMode::RelativeToSeaFloor], true))) {
                throw new InvalidKml('Unsupported KML altitude mode.');
            }
            $this->mode = $mode;
        } elseif ('name' === $role || 'description' === $role) {
            $this->$role = trim($this->text);
        } elseif ('line' === $role || 'point' === $role) {
            if (!$this->coordinatesSeen || ('line' === $role ? $this->geometryPoints < 2 : 1 !== $this->geometryPoints)) {
                throw new InvalidKml('KML LineString requires at least two coordinates; Point requires one.');
            }
        } elseif ('placemark' === $role) {
            $this->events[] = new KmlObject($this->index, $this->name, $this->description, $this->lines, $this->linePoints,
                $this->points, $this->objectIgnored, $this->omittedAltitude);
        }
        array_pop($this->stack);
    }

    private function coordinates(string $data, bool $last): void
    {
        $line = 'line' === $this->stack[count($this->stack) - 2];
        foreach ($this->tokens->feed($data, $last) as [$longitude, $latitude, $altitude]) {
            $this->events[] = new KmlPoint($this->index, ++$this->ordinal, $line ? $this->lines - 1 : null,
                $this->geometryPoints++, $latitude, $longitude, $altitude, $this->mode);
            if ($line) {
                ++$this->linePoints;
                if (null !== $altitude && AltitudeMode::Absolute !== $this->mode) {
                    ++$this->omittedAltitude;
                }
            } else {
                ++$this->points;
            }
        }
    }
}
