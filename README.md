# Endurance KML

Streaming KML 2.2 subset reader for PHP 8.5 with ext-xml.

```bash
composer require endurance/kml
```

```php
use Endurance\Kml\PathReader;

$points = new PathReader()->objectPoints($input, 'kml.v2/placemark[1]');
foreach ($points as $point) {
    $stage($point);
}
$result = $points->getReturn();
```

- `PathReader` supports LineString, Point, MultiGeometry and GX Track/MultiTrack.
  GX timestamps and positions pair by occurrence; missing positions preserve gaps.
  Lexical times remain unchanged. A private disk queue bounds timestamp memory.
- `Reader` retains the separate `kml.v1` grammar without GX tracks. Its locators
  cannot be upgraded to `kml.v2`; each locator addresses the same immutable bytes.
  `objects()` discovers Placemarks, including empty/unsupported ones.
- Consume the whole document and obtain `getReturn()` before publishing any
  staged output. Early yields are provisional; invalid unselected content or
  an invalid XML tail fails the read. The caller retains input ownership,
  including non-seekable input and cancellation through the progress callback.
- DTD/entity declarations and bounded source/scalar/depth limits are enforced.
  No KMZ, external retrieval or complete XSD validation. Invalid supported input
  raises `InvalidKml`; ignored content is counted in the result.

Types in `Internal` and elements marked `@internal` are not public API.

License: [MPL-2.0](LICENSE).
