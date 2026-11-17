# jovian/venusian-thor

The `thorvg` rasterization and painting drivers for [Venusian Topside](https://github.com/VenusianPHP/topside): shapes drawn by [ThorVG](https://github.com/thorvg/thorvg) through [ext-thorvg](https://github.com/php-io-extensions/thorvg).

```bash
composer require jovian/venusian-thor
```

The service provider registers `thorvg` on Topside's rasterization and painting managers. Pick it like any other driver, or let `auto` try it; without ext-thorvg loaded the drivers refuse to construct and `auto` moves on.

## What ThorVG does here

- **Anti-aliased path fills** (rasterization), which is everything Topside's shared rasterizer lowers shapes and strokes to.
- **Anti-aliased shapes and gradients** (painting): `path`, `gradpath`, `polyline`, `ellipse`, `ring`, `roundrect` and `roundring` commands.
- **Everything else comes from VelvetGE:** hard edges, clears, shadows, glass, layers, images, glyphs and spans.

## Images

`thorvg` on Images reads PNG and JPEG. A picture is drawn at its natural size onto a software canvas aimed at the frame, in a straight colorspace. An opaque pixel matches the file. A partial pixel can be 1 off, because ThorVG premultiplies and the straight target divides that back out. The draw replaces the frame: it does not blend into the pixels that were there. WebP is a loader this build has; a flat `0x102030` stored at quality 100 came back `0x301522`, so WebP is not claimed. SVG has no fixed pixel size.

## Where it draws

- **In place**, straight into the framebuffer's memory, when that memory is 32-bit rows in the order B G R A or R G B A, top row first. This holds for a frame with any transparency: ThorVG's premultiplied source-over on the stored bytes is the same arithmetic as VelvetGE's blend.
- **As coverage**, for every other layout (A R G B, A B G R, 565, 24-bit, mono, palettes, stores with no memory): ThorVG fills the path in white on a scratch buffer, `tvg_swcanvas_get_alpha()` reads the coverage out in one call, and the framebuffer's own store blends it. A painter on such a frame is VelvetGE's.

## How it differs from VelvetGE

- **Edges.** Coverage at a shape's edge is ThorVG's own. On simple shapes every pixel is within 11 of VelvetGE's coverage (out of 255). Where a path's contours cross inside one pixel the two resolve the overlap differently, and a pixel can differ by up to 197; under 0.5% of a shape's pixels, with areas within 0.4% (`ThorVGScanner::TOLERANCE`).
- **Blending in place.** Against VelvetGE blending ThorVG's own coverage, a shape drawn in place is at most 5 off per byte: of the bytes that differ, 96.9% are 1 off, 3.1% are 2, under 0.02% are 3 to 5 (`ThorVGSurface::BLEND`). Shapes drawn over one another add up.
- **Painting.** Against the native painter, one shape at a time: worst per-byte distance 4 on rects, 6 on lines, 19 on ellipses, 31 on round rects, 34 on rings (`ThorVGBrush::TOLERANCE` = 40). Paths, strokes, polylines and gradient paths pass 40 only where their contours cross inside a pixel, under 0.4% of the pixels that differ. Gradients are ThorVG's own, built from VelvetGE's 256-colour ramp: 99.8% of differing pixels are within 8.
- **Damage.** In place, the region reported for a shape is its extents inside the clip. It always holds every pixel changed; a sliver too thin to cover a pixel can make it larger than the coverage route reports.

Speed, 200 draws on a 1024×768 B G R A frame (topside e4139a4, Apple silicon): fill path 58 ms (`ext` 115, `native` 3,879), stroke path 207 (289, 9,171), linear gradient 152 (241, 4,268), radial gradient 156 (304, 4,977), small rects 7 (4, 116).

## Three ThorVG behaviours the drivers work around

- The canvas is made with `TVG_ENGINE_OPTION_NONE`. With the default option ThorVG clears the regions it redraws, which erases earlier drawing on a reused canvas.
- The canvas colorspace is a premultiplied one. ThorVG's straight-alpha ("S") colorspaces rewrite the whole buffer after every draw.
- A four-point contour is given a fifth point on its first edge. ThorVG fills a four-line axis-aligned rectangle on whole pixels with no anti-aliasing.

## Requirements

PHP 8.4+, ext-thorvg 0.11 with `tvg_swcanvas_get_alpha()` (php-io-extensions/thorvg 0306b19 or later), venusian/topside 0.11.5+ (e4139a4 or later).

## License

MIT.
