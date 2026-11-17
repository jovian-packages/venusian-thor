<?php

namespace Jovian\Rendering\Thor\Framebuffers;

use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\GradientSpread;
use Surface\Rendering\Framebuffers\FramebufferDelegate;
use Tvg_Canvas;
use Tvg_Color_Stop;
use Tvg_Matrix;
use Tvg_Paint;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\GradientFramebuffer;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;
use VelvetGE\Framebuffers\ImagePlacement;
use VelvetGE\Framebuffers\Layout;
use VelvetGE\Framebuffers\MaskList;
use VelvetGE\Contracts\Framebuffers\PagedFramebuffer as PagedFramebufferContract;
use VelvetGE\Framebuffers\ReadsBlitSources;
use VelvetGE\Framebuffers\SpanList;

/**
 * One ThorVG software canvas over its own pixels, ThorVG's way. ThorVG draws into 32-bit words:
 * R G B A bytes (ABGR8888) or B G R A bytes (ARGB8888), so the canvas is an RGBA8 or a BGRA8
 * framebuffer. The pixels are a string ThorVG draws into, holding the format's bytes.
 *
 * ThorVG has no operation that only moves bytes: every draw is a paint blended over the pixels.
 * So writes that set pixels (values, fills, copies, scrolls, RGBA8 in) are byte moves on the
 * string, and the canvas is pointed at the new string after each. Everything that blends is a
 * ThorVG paint: a rectangle filled with the colour or the gradient, or a picture of another
 * framebuffer's pixels (loaded straight, premultiplied by ThorVG), cut by an alpha-mask picture
 * of the coverage. The canvas is made with TVG_ENGINE_OPTION_NONE, so a draw keeps what is there.
 *
 * ThorVG blends as if the pixels were premultiplied, so over a translucent destination pixel its
 * result is not Lustrous's straight-alpha lerp: blended bytes land within TOLERANCE of
 * lustrous-php. No effects (no filter chains, Kawase levels or glass) and no kinds.
 */
final class ThorVGCanvas extends FramebufferDelegate implements CompositingFramebuffer, GradientFramebuffer, ScrollingFramebuffer, PaintTarget
{
    use ReadsBlitSources;

    /** How far a blended byte may land from lustrous-php's: 5% of 255, as ruled for library framebuffers. */
    public const int TOLERANCE = 13;

    /** Pixels of repeated edge around a source picture: past ThorVG's soft outline and its bilinear reach. */

    private static bool $engine = false;

    private string $pixels;

    private Tvg_Canvas $canvas;

    /** ThorVG's colorspace for the format: its premultiplied words with this byte order. */
    private int $colorspace;

    /** @throws FramebufferException For any format but RGBA8 and BGRA8, or a big-endian host. */
    public function __construct(
        private readonly FormatSpec $format,
        private readonly int $width,
        private readonly int $height,
    ) {
        $this->colorspace = match (true) {
            pack('V', 1) !== pack('L', 1) => throw FramebufferException::unsupportedFormat($format, 'ThorVG writes host-order words; this host is big-endian.'),
            $format->equals(FormatSpec::rgba8()) => TVG_COLORSPACE_ABGR8888,
            $format->equals(FormatSpec::bgra8()) => TVG_COLORSPACE_ARGB8888,
            default => throw FramebufferException::unsupportedFormat($format, 'ThorVG draws into RGBA8 and BGRA8 only.'),
        };
        Layout::size($width, $height);
        if (! self::$engine) {
            self::ok(tvg_engine_init(0), 'tvg_engine_init');
            self::$engine = true;
        }
        $this->canvas = tvg_swcanvas_create(TVG_ENGINE_OPTION_NONE) ?? throw new FramebufferException('ThorVG has no software canvas in this build.');
        $this->pixels = str_repeat("\0", $width * $height * 4);
        $this->retarget();
    }

    /** A clone gets its own pixels and canvas: ThorVG draws into the string, so two frames must not share one. */
    public function __clone()
    {
        $this->canvas = tvg_swcanvas_create(TVG_ENGINE_OPTION_NONE) ?? throw new FramebufferException('ThorVG has no software canvas in this build.');
        $this->pixels = self::own($this->pixels);
        $this->retarget();
    }

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function hostFormat(): FormatSpec
    {
        return $this->format;
    }

    /** The string the canvas draws into, as memory: valid until the next byte move replaces it. */
    public function memory(): ?PixelMemory
    {
        return new PixelMemory($this->pointer(), $this->width, $this->height, $this->width * 4, $this->format, $this);
    }

    public function pointer(): int
    {
        return tvg_swcanvas_get_target_address($this->canvas);
    }

    public function drewInto(?Region ...$regions): static
    {
        foreach ($regions as $region) {
            $this->wrote($region);
        }

        return $this;
    }

    public function getPixel(int $x, int $y): int
    {
        $this->guard($x, $y);

        return $this->decode(substr($this->pixels, ($y * $this->width + $x) * 4, 4));
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->guard($x, $y);
        $this->pixels = substr_replace($this->pixels, $this->encode($value), ($y * $this->width + $x) * 4, 4);
        $this->retarget();

        return $this->wrote(new Region($x, $y, 1, 1));
    }

    public function setPixels(array $pixels): static
    {
        $bounds = $this->bounds($pixels, 3, '[x, y, value]');
        foreach ($pixels as [$x, $y, $value]) {
            $this->pixels = substr_replace($this->pixels, $this->encode($value), ($y * $this->width + $x) * 4, 4);
        }
        $this->retarget();

        return $this->wrote($bounds);
    }

    public function setRegion(array $coordinates, int $value): static
    {
        $bounds = $this->bounds($coordinates, 2, '[x, y]');
        $cell = $this->encode($value);
        foreach ($coordinates as [$x, $y]) {
            $this->pixels = substr_replace($this->pixels, $cell, ($y * $this->width + $x) * 4, 4);
        }
        $this->retarget();

        return $this->wrote($bounds);
    }

    /** A rect of one value, clipped to the surface: a rect straddling the edge is ordinary. */
    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $region = (new Region($x, $y, max(0, $width), max(0, $height)))->intersect($this->whole());
        if (is_null($region)) {
            return $this;
        }
        if (($color & 0xFF) === 0xFF) {
            // an opaque colour over whole pixels is what ThorVG's own fill writes, byte for byte, and
            // it writes it where the pixels are: nothing of the frame is copied
            $rect = $this->rect($region);
            self::ok(tvg_shape_set_fill_color($rect, ($color >> 24) & 0xFF, ($color >> 16) & 0xFF, ($color >> 8) & 0xFF, 0xFF), 'tvg_shape_set_fill_color');
            $this->paint($rect, $region);

            return $this->wrote($region);
        }
        // a colour that is not opaque would be blended by ThorVG, not set: its bytes are placed
        $this->place($region, str_repeat($this->encode($color), $region->width * $region->height));

        return $this->wrote($region);
    }

    public function clear(): static
    {
        return $this->fill(0);
    }

    public function fill(int $color): static
    {
        $this->pixels = str_repeat($this->encode($color), $this->width * $this->height);
        $this->retarget();

        return $this->wrote($this->whole());
    }

    public function writeRgba8(string $rgba8, int $width, int $height, int $x = 0, int $y = 0): static
    {
        if ($width < 1 || $height < 1 || strlen($rgba8) !== $width * $height * 4) {
            throw new FramebufferException("writeRgba8() takes {$width}x{$height} RGBA8 pixels (".(max(0, $width) * max(0, $height) * 4).' bytes), got '.strlen($rgba8).'.');
        }
        $target = (new Region($x, $y, $width, $height))->intersect($this->whole());
        if (is_null($target)) {
            return $this;
        }
        $mine = $this->colorspace === TVG_COLORSPACE_ABGR8888 ? $rgba8 : self::swapped($rgba8);
        $rows = '';
        for ($row = $target->y - $y; $row < $target->bottom() - $y; $row++) {
            $rows .= substr($mine, ($row * $width + $target->x - $x) * 4, $target->width * 4);
        }
        $this->place($target, $rows);

        return $this->wrote($target);
    }

    public function blitTo(InstanceThatManagesAFramebuffer $target, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    public function blitFrom(InstanceThatManagesAFramebuffer $source, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        // ThorVG has no copy operation, so the bytes cross here. The source gives them in this
        // canvas's own format, converting in its own library, so nothing is converted in PHP.
        if (! ($source instanceof PagedFramebufferContract)) {
            $target = (new Region($offset_x, $offset_y, $source->viewportWidth(), $source->viewportHeight()))->intersect($this->whole());
            if (is_null($target)) {
                return $this;
            }
            $from = new Region($target->x - $offset_x, $target->y - $offset_y, $target->width, $target->height);
            $this->place($target, $source->flushRegion($from, $this->format));

            return $this->wrote($target);
        }
        [$rgba8, $width, $height, $top] = $this->blitSource($source);

        return $this->writeRgba8($rgba8, $width, $height, $offset_x, $offset_y + $top);
    }

    public function toRgba8(): string
    {
        return $this->colorspace === TVG_COLORSPACE_ABGR8888 ? self::own($this->pixels) : self::swapped($this->pixels);
    }

    public function dump(?int $layer = null): string
    {
        if (! is_null($layer) && $layer !== 0) {
            throw new FramebufferException("A ThorVG canvas has one layer, 0; got {$layer}.");
        }

        return self::own($this->pixels);
    }

    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        return self::answer($this->in($this->whole(), $spec), $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        if ($region->isEmpty() || $region->x < 0 || $region->y < 0 || $region->right() > $this->width || $region->bottom() > $this->height) {
            throw FramebufferException::outsideSurface($region, $this->width, $this->height);
        }

        return self::answer($this->in($region, $spec), $as_array);
    }

    public function scratch(int $width, int $height): InstanceThatManagesAFramebuffer
    {
        return new self(FormatSpec::rgba8(), $width, $height);
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    public function scroll(Region $region, int $dx, int $dy): void
    {
        $clip = $region->intersect($this->whole());
        $source = is_null($clip) ? null : self::scrollSource($clip, $dx, $dy);
        if (is_null($source)) {
            return;
        }
        $this->place(new Region($source->x + $dx, $source->y + $dy, $source->width, $source->height), $this->read($source));
        $this->touched($clip);
    }

    public function paintSpans(string $spans, int $rgba8): static
    {
        [$list, $bounds] = SpanList::read($spans, $rgba8, $this->width, $this->height);
        if (is_null($bounds)) {
            return $this;
        }
        // One mask per pass. Spans paint in list order, so a span overlapping one already in the
        // mask blends a second time: the mask so far is painted first.
        $blank = str_repeat("\0", $bounds->width * $bounds->height);
        $mask = $blank;
        $taken = [];
        foreach ($list as [$y, $x, $length, $coverage]) {
            foreach ($taken[$y] ?? [] as [$x0, $x1]) {
                if ($x < $x1 && $x + $length > $x0) {
                    $this->solidThrough($rgba8, $mask, $bounds);
                    $mask = $blank;
                    $taken = [];
                    break;
                }
            }
            $taken[$y][] = [$x, $x + $length];
            $mask = substr_replace($mask, str_repeat(chr($coverage), $length), ($y - $bounds->y) * $bounds->width + $x - $bounds->x, $length);
        }
        $this->solidThrough($rgba8, $mask, $bounds);

        return $this->wrote($bounds);
    }

    public function paintMasks(array $masks, int $rgba8, Region $clip): static
    {
        MaskList::check($masks, $rgba8, $clip, $this->width, $this->height);
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach ($masks as [$coverage, $width, $mx, $my]) {
            $y0 = max($my, $clip->y);
            $y1 = min($my + intdiv(strlen($coverage), $width), $clip->bottom());
            $x0 = max($mx, $clip->x);
            $x1 = min($mx + $width, $clip->right());
            if ($y0 >= $y1 || $x0 >= $x1) {
                continue;
            }
            $w = $x1 - $x0;
            $rows = '';
            for ($y = $y0; $y < $y1; $y++) {
                $row = substr($coverage, ($y - $my) * $width + $x0 - $mx, $w);
                $lead = strspn($row, "\0");
                if ($lead < $w) {
                    $left = min($left, $x0 + $lead);
                    $right = max($right, $x0 + strlen(rtrim($row, "\0")));
                    $top = min($top, $y);
                    $bottom = max($bottom, $y + 1);
                }
                $rows .= $row;
            }
            $this->solidThrough($rgba8, $rows, new Region($x0, $y0, $w, $y1 - $y0));
        }

        return $right === PHP_INT_MIN ? $this : $this->wrote(new Region($left, $top, $right - $left, $bottom - $top));
    }

    /**
     * Another framebuffer's pixels through $placement, ThorVG's way: a raw picture of the source
     * loaded straight (ThorVG premultiplies it), tinted first when tinted, with the placement as its
     * transform, ThorVG's nearest or bilinear filter and $opacity, cut by an alpha mask of the
     * pixels whose centre maps inside the source (or $window), times $coverage.
     */
    public function paintImage(InstanceThatManagesAFramebuffer $source, Affine $placement, int $opacity = 255, Filter $filter = Filter::NEAREST, ?Region $clip = null, int $tint = 0xFFFFFFFF, ?array $window = null, ?string $coverage = null): static
    {
        // A straight RGBA8 or BGRA8 source with packed rows is loaded from its memory: ThorVG copies
        // and premultiplies it in C. A tint is a per-channel multiply ThorVG has no operator for,
        // so a tinted picture, like a source in another layout or none, is read back.
        $memory = $tint === 0xFFFFFFFF ? $this->sourceMemory($source) : null;
        $space = match (true) {
            is_null($memory) || $memory->stride !== $memory->width * 4 => null,
            $memory->format->equals(FormatSpec::rgba8()) => TVG_COLORSPACE_ABGR8888S,
            $memory->format->equals(FormatSpec::bgra8()) => TVG_COLORSPACE_ARGB8888S,
            default => null,
        };
        if (is_null($space)) {
            [$data, $sw, $sh, $top] = $this->blitSource($source);
            $space = TVG_COLORSPACE_ABGR8888S;
        } else {
            [$data, $sw, $sh, $top] = [$memory->address, $memory->width, $memory->height, 0];
        }
        $plan = ImagePlacement::plan($placement, $sw, $sh, $top, $this->whole(), $clip, $opacity, $window);
        if (is_null($plan) || $opacity === 0) {
            return $this;
        }
        [$target, $inverse, $held] = $plan;
        [$a, $b, $c, $d, $e, $f] = $inverse;
        $forward = (new Affine($a, $b, $c, $d, $e, $f))->inverse();
        if (is_null($forward)) {
            return $this;
        }
        if ($tint !== 0xFFFFFFFF) {
            $data = self::tint($data, $tint);
        }
        // ThorVG resamples the picture its own way (its rounding, its soft outline): that is
        // ThorVG's result, measured against lustrous-php, not corrected toward it.
        $picture = tvg_picture_new() ?? throw new FramebufferException('ThorVG could not make a picture.');
        self::ok(tvg_picture_load_raw($picture, $data, $sw, $sh, $space, true), 'tvg_picture_load_raw');
        self::ok(tvg_picture_set_filter($picture, $filter === Filter::LINEAR ? TVG_FILTER_METHOD_BILINEAR : TVG_FILTER_METHOD_NEAREST), 'tvg_picture_set_filter');
        self::ok(tvg_paint_set_transform($picture, self::matrix($forward)), 'tvg_paint_set_transform');
        if ($opacity < 255) {
            self::ok(tvg_paint_set_opacity($picture, $opacity), 'tvg_paint_set_opacity');
        }
        $mask = $this->footprint($target, $inverse, $held ?? [0.0, 0.0, (float) $sw, (float) $sh], is_null($coverage) ? null : $this->coverageRows($coverage, $target));
        self::ok(tvg_paint_set_mask_method($picture, $this->alphaMask($mask, $target), TVG_MASK_METHOD_ALPHA), 'tvg_paint_set_mask_method');
        $this->paint($picture, $target);

        return $this->wrote($target);
    }

    /**
     * A gradient through spans, ThorVG's way: a rectangle over the spans filled with a ThorVG linear
     * (t = u) or radial (t = hypot(u, v)) gradient of the ramp's 256 colours, the gradient's
     * transform the placement the inverse undoes, cut by an alpha mask of the spans' coverage.
     */
    public function paintGradient(string $spans, string $ramp, string $kind, GradientSpread $spread, array $inverse): static
    {
        if (strlen($ramp) !== 1024) {
            throw new FramebufferException('paintGradient() takes a ramp of 256 RGBA colours (1024 bytes), got '.strlen($ramp).'.');
        }
        if (count($inverse) !== 6 || array_filter($inverse, fn (mixed $n): bool => (is_int($n) || is_float($n)) && is_finite($n)) !== $inverse) {
            throw new FramebufferException('paintGradient() takes an inverse of six finite numbers.');
        }
        if (! in_array($kind, ['linear', 'radial'], true)) {
            throw new FramebufferException("paintGradient() takes 'linear' or 'radial', got '{$kind}'.");
        }
        [$list, $bounds] = SpanList::read($spans, 0xFFFFFFFF, $this->width, $this->height);
        if (is_null($bounds)) {
            return $this;
        }
        [$a, $b, $c, $d, $e, $f] = array_values($inverse);
        $forward = (new Affine((float) $a, (float) $b, (float) $c, (float) $d, (float) $e, (float) $f))->inverse();
        if (is_null($forward)) {
            return $this;
        }
        $gradient = ($kind === 'radial' ? tvg_radial_gradient_new() : tvg_linear_gradient_new()) ?? throw new FramebufferException('ThorVG could not make a gradient.');
        $kind === 'radial'
            ? self::ok(tvg_radial_gradient_set($gradient, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0), 'tvg_radial_gradient_set')
            : self::ok(tvg_linear_gradient_set($gradient, 0.0, 0.0, 1.0, 0.0), 'tvg_linear_gradient_set');
        $stops = [];
        for ($i = 0; $i < 256; $i++) {
            $stops[] = new Tvg_Color_Stop($i / 255, ord($ramp[4 * $i]), ord($ramp[4 * $i + 1]), ord($ramp[4 * $i + 2]), ord($ramp[4 * $i + 3]));
        }
        self::ok(tvg_gradient_set_color_stops($gradient, $stops, 256), 'tvg_gradient_set_color_stops');
        self::ok(tvg_gradient_set_spread($gradient, match ($spread) {
            GradientSpread::PAD => TVG_STROKE_FILL_PAD,
            GradientSpread::REPEAT => TVG_STROKE_FILL_REPEAT,
            GradientSpread::REFLECT => TVG_STROKE_FILL_REFLECT,
        }), 'tvg_gradient_set_spread');
        self::ok(tvg_gradient_set_transform($gradient, self::matrix($forward)), 'tvg_gradient_set_transform');

        $mask = str_repeat("\0", $bounds->width * $bounds->height);
        foreach ($list as [$y, $x, $length, $coverage]) {
            $mask = substr_replace($mask, str_repeat(chr($coverage), $length), ($y - $bounds->y) * $bounds->width + $x - $bounds->x, $length);
        }
        $rect = $this->rect($bounds);
        self::ok(tvg_shape_set_gradient($rect, $gradient), 'tvg_shape_set_gradient');
        self::ok(tvg_paint_set_mask_method($rect, $this->alphaMask($mask, $bounds), TVG_MASK_METHOD_ALPHA), 'tvg_paint_set_mask_method');
        $this->paint($rect, $bounds);

        return $this->wrote($bounds);
    }

    /** Points the canvas at the pixels as they now are: a byte move gave them a new string. */
    private function retarget(): void
    {
        self::ok(tvg_swcanvas_set_target($this->canvas, $this->pixels, $this->width, $this->width, $this->height, $this->colorspace), 'tvg_swcanvas_set_target');
    }

    /** Draws one paint over the pixels, inside $at, keeping what is there, and takes it off the canvas again. */
    private function paint(Tvg_Paint $paint, Region $at): void
    {
        // The viewport (the clip) can only be set while the canvas is synced: before the paint is added.
        self::ok(tvg_canvas_set_viewport($this->canvas, $at->x, $at->y, $at->width, $at->height), 'tvg_canvas_set_viewport');
        self::ok(tvg_canvas_add($this->canvas, $paint), 'tvg_canvas_add');
        self::ok(tvg_canvas_update($this->canvas), 'tvg_canvas_update');
        self::ok(tvg_canvas_draw($this->canvas, false), 'tvg_canvas_draw');
        self::ok(tvg_canvas_sync($this->canvas), 'tvg_canvas_sync');
        self::ok(tvg_canvas_remove($this->canvas, $paint), 'tvg_canvas_remove');
        // remove() leaves the canvas painting; sync() puts it back to synced so the next viewport is allowed.
        self::ok(tvg_canvas_sync($this->canvas), 'tvg_canvas_sync');
    }

    /** 0xRRGGBBAA, straight, as a rectangle over $at cut by the A8 coverage $mask. */
    private function solidThrough(int $rgba8, string $mask, Region $at): void
    {
        if (($rgba8 & 0xFF) === 0) {
            return;
        }
        $rect = $this->rect($at);
        self::ok(tvg_shape_set_fill_color($rect, ($rgba8 >> 24) & 0xFF, ($rgba8 >> 16) & 0xFF, ($rgba8 >> 8) & 0xFF, $rgba8 & 0xFF), 'tvg_shape_set_fill_color');
        self::ok(tvg_paint_set_mask_method($rect, $this->alphaMask($mask, $at), TVG_MASK_METHOD_ALPHA), 'tvg_paint_set_mask_method');
        $this->paint($rect, $at);
    }

    private function rect(Region $at): Tvg_Paint
    {
        $rect = tvg_shape_new() ?? throw new FramebufferException('ThorVG could not make a shape.');
        self::ok(tvg_shape_append_rect($rect, (float) $at->x, (float) $at->y, (float) $at->width, (float) $at->height, 0.0, 0.0, true), 'tvg_shape_append_rect');

        return $rect;
    }

    /** An A8 mask over $at as a ThorVG picture whose alpha is the coverage (colour 0), placed at $at. */
    private function alphaMask(string $mask, Region $at): Tvg_Paint
    {
        // Each coverage byte as the word 0xCC000000, widened in C.
        $picture = tvg_picture_new() ?? throw new FramebufferException('ThorVG could not make a mask picture.');
        self::ok(tvg_picture_load_alpha($picture, $mask, $at->width, $at->height), 'tvg_picture_load_alpha');
        self::ok(tvg_picture_set_filter($picture, TVG_FILTER_METHOD_NEAREST), 'tvg_picture_set_filter');
        self::ok(tvg_paint_translate($picture, (float) $at->x, (float) $at->y), 'tvg_paint_translate');

        return $picture;
    }

    /** Packed rows of this format's bytes into a rect of the string, and the canvas pointed at the result. */
    private function place(Region $region, string $rows): void
    {
        // One new string, built in one pass: a substr_replace per row would copy the whole surface
        // once per row.
        $row = $region->width * 4;
        $line = $this->width * 4;
        $start = ($region->y * $this->width + $region->x) * 4;
        if ($region->x === 0 && $region->width === $this->width) {
            $this->pixels = substr($this->pixels, 0, $start).$rows.substr($this->pixels, $start + strlen($rows));
        } else {
            $pieces = [substr($this->pixels, 0, $start)];
            for ($y = 0; $y < $region->height; $y++) {
                $pieces[] = substr($rows, $y * $row, $row);
                $at = $start + $y * $line + $row;
                $pieces[] = substr($this->pixels, $at, $y === $region->height - 1 ? null : $line - $row);
            }
            $this->pixels = implode('', $pieces);
        }
        $this->retarget();
    }

    /** A rect's bytes in this format, rows packed, top row first. */
    private function read(Region $region): string
    {
        if ($region->x === 0 && $region->width === $this->width) {
            return self::own(substr($this->pixels, $region->y * $this->width * 4, $region->height * $this->width * 4));
        }
        $rows = '';
        for ($y = $region->y; $y < $region->bottom(); $y++) {
            $rows .= substr($this->pixels, ($y * $this->width + $region->x) * 4, $region->width * 4);
        }

        return $rows;
    }

    /** A rect's bytes in $spec: RGBA8 or BGRA8 (swapping bytes 0 and 2 to cross); nothing else. */
    private function in(Region $region, FormatSpec $spec): string
    {
        $bytes = $this->read($region);

        return match (true) {
            $spec->equals($this->format) => $bytes,
            $spec->equals(FormatSpec::rgba8()), $spec->equals(FormatSpec::bgra8()) => self::swapped($bytes),
            default => throw FramebufferException::unsupportedFormat($spec, 'a ThorVG canvas converts to RGBA8 and BGRA8 only.'),
        };
    }

    /** A pixel value (0xRRGGBBAA) as this format's bytes. */
    private function encode(int $value): string
    {
        [$r, $g, $b, $a] = [($value >> 24) & 0xFF, ($value >> 16) & 0xFF, ($value >> 8) & 0xFF, $value & 0xFF];

        return $this->colorspace === TVG_COLORSPACE_ABGR8888 ? pack('C4', $r, $g, $b, $a) : pack('C4', $b, $g, $r, $a);
    }

    private function decode(string $bytes): int
    {
        [, $p, $g, $q, $a] = unpack('C4', $bytes);
        [$r, $b] = $this->colorspace === TVG_COLORSPACE_ABGR8888 ? [$p, $q] : [$q, $p];

        return ($r << 24) | ($g << 16) | ($b << 8) | $a;
    }

    /**
     * Per row of $target, 255 where the pixel's centre maps (through $inverse) inside $held, times
     * the coverage of the span holding each pixel when $coverage rows are given.
     *
     * @param  array{float, float, float, float, float, float}  $inverse
     * @param  array{float, float, float, float}  $held
     * @param  list<string>|null  $coverage
     */
    private function footprint(Region $target, array $inverse, array $held, ?array $coverage): string
    {
        [$a, $b, $c, $d, $e, $f] = $inverse;
        [$u0, $v0, $u1, $v1] = $held;
        $mask = '';
        for ($row = 0; $row < $target->height; $row++) {
            $cy = $target->y + $row + 0.5;
            [$lo, $hi] = [-INF, INF];
            foreach ([[$a, $c * $cy + $e, $u0, $u1], [$b, $d * $cy + $f, $v0, $v1]] as [$k, $m, $low, $high]) {
                if ($k == 0.0) {
                    if (! ($m >= $low && $m < $high)) {
                        [$lo, $hi] = [INF, -INF];
                    }
                    continue;
                }
                [$p, $q] = [($low - $m) / $k, ($high - $m) / $k];
                if ($k > 0) {
                    $lo = max($lo, $p);
                    $hi = min($hi, $q);
                } else {
                    $lo = max($lo, $q);
                    $hi = min($hi, $p);
                }
            }
            $first = max($target->x, (int) ceil($lo - 0.5));
            $last = min($target->right(), (int) ceil($hi - 0.5));
            $line = str_repeat("\0", $target->width);
            if ($first < $last) {
                $line = substr_replace($line, str_repeat("\xFF", $last - $first), $first - $target->x, $last - $first);
            }
            if (! is_null($coverage)) {
                // 255 times the coverage is the coverage: keep it where the footprint is.
                $line = $line & $coverage[$row];
            }
            $mask .= $line;
        }

        return $mask;
    }

    /** @return list<string> one coverage row per row of $target (0 where no span holds a pixel) */
    private function coverageRows(string $spans, Region $target): array
    {
        [$list] = SpanList::read($spans, 0xFFFFFFFF, $this->width, $this->height);
        $rows = array_fill(0, $target->height, str_repeat("\0", $target->width));
        foreach ($list as [$y, $x, $length, $coverage]) {
            $x0 = max($x, $target->x);
            $x1 = min($x + $length, $target->right());
            if ($y < $target->y || $y >= $target->bottom() || $x0 >= $x1) {
                continue;
            }
            $rows[$y - $target->y] = substr_replace($rows[$y - $target->y], str_repeat(chr($coverage), $x1 - $x0), $x0 - $target->x, $x1 - $x0);
        }

        return $rows;
    }

    /** Straight RGBA8 times a 0xRRGGBBAA tint per channel, alpha included: c = (c × t + 127) / 255. */
    private static function tint(string $rgba8, int $tint): string
    {
        $t = [($tint >> 24) & 0xFF, ($tint >> 16) & 0xFF, ($tint >> 8) & 0xFF, $tint & 0xFF];
        $out = '';
        for ($i = 0, $n = strlen($rgba8); $i < $n; $i++) {
            $out .= chr(intdiv(ord($rgba8[$i]) * $t[$i & 3] + 127, 255));
        }

        return $out;
    }

    /**
     * RGBA8 to BGRA8 or back: bytes 0 and 2 of every pixel swap.
     * chisle: a PHP pass over the words; ThorVG has no pixel converter.
     */
    private static function swapped(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        return pack('V*', ...array_map(
            static fn (int $w): int => ($w & 0xFF00FF00) | (($w >> 16) & 0xFF) | (($w & 0xFF) << 16),
            unpack('V*', $bytes),
        ));
    }

    /** A copy of $bytes no other string shares: ThorVG writes where it is pointed. */
    private static function own(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }
        $copy = $bytes;
        $copy[0] = $bytes[0];

        return $copy;
    }

    /** a, b, c, d, e, f (x' = a·x + c·y + e, y' = b·x + d·y + f) as ThorVG's row-major matrix. */
    private static function matrix(Affine $m): Tvg_Matrix
    {
        return new Tvg_Matrix($m->a, $m->c, $m->e, $m->b, $m->d, $m->f, 0.0, 0.0, 1.0);
    }

    private static function ok(int $result, string $call): void
    {
        $result === TVG_RESULT_SUCCESS || throw new FramebufferException("ThorVG's {$call}() answered {$result}.");
    }

    private function whole(): Region
    {
        return Region::wholeSurface($this->width, $this->height);
    }

    /**
     * The whole list checked before anything is written, and its bounding box.
     *
     * @param  array<int, array<int, int>>  $pixels
     */
    private function bounds(array $pixels, int $arity, string $shape): ?Region
    {
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach (array_values($pixels) as $position => $pixel) {
            if (! is_array($pixel) || count($pixel) !== $arity || ! is_int($pixel[0] ?? null) || ! is_int($pixel[1] ?? null) || ($arity === 3 && ! is_int($pixel[2] ?? null))) {
                throw new FramebufferException("Pixel {$position} is not {$shape}.");
            }
            $this->guard($pixel[0], $pixel[1]);
            $left = min($left, $pixel[0]);
            $top = min($top, $pixel[1]);
            $right = max($right, $pixel[0]);
            $bottom = max($bottom, $pixel[1]);
        }

        return $pixels === [] ? null : new Region($left, $top, $right - $left + 1, $bottom - $top + 1);
    }
}
