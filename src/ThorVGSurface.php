<?php

namespace Jovian\Rendering\Thor;

use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;
use Surface\Contracts\Rendering\RenderingException;
use Surface\VelvetGE\Framebuffers\Layout;
use Tvg_Canvas;
use Tvg_Color_Stop;
use Tvg_Matrix;
use Tvg_Paint;

/**
 * One ThorVG software canvas and the one shape it draws, over a framebuffer's memory or over a
 * cleared scratch buffer.
 *
 * Three things here are measured, not assumed (2026-11-13):
 * - The canvas is made with TVG_ENGINE_OPTION_NONE. With the default option ThorVG tracks dirty
 *   regions and clears them before each draw, which erases what was drawn before.
 * - The colorspace is a premultiplied one (ARGB8888 or ABGR8888). ThorVG's source-over on the
 *   stored bytes is VelvetGE's blend (colour·a + pixel·(1 − a), alpha a + pixelAlpha·(1 − a)), so
 *   it lands within BLEND of VelvetGE on a frame with any alpha. The "S" colorspaces rewrite the
 *   whole buffer after every draw and are not used.
 * - The viewport is the clip; it can only be set while the canvas is synced.
 */
final class ThorVGSurface
{
    /**
     * Greatest per-byte distance between ThorVG drawing a shape in place and VelvetGE blending
     * ThorVG's own coverage of it (measured 2026-11-13: 3,000 random translucent polygons a format
     * over random pixels of every alpha). Of the 3.7 million bytes that differed at all, 96.9% were
     * 1 off, 3.1% were 2, and under 0.02% were 3 to 5: ThorVG multiplies by coverage and by alpha
     * in separate 8-bit steps. Shapes drawn over one another add up.
     */
    public const int BLEND = 5;

    private static bool $engine = false;

    /** @var array<string, list<Tvg_Color_Stop>> colour stops by ramp */
    private static array $ramps = [];

    private Tvg_Canvas $canvas;

    private Tvg_Paint $shape;

    /** @param string|int $target the scratch bytes, or the memory's address */
    private function __construct(
        private string|int $target,
        private readonly int $pixelsPerRow,
        private readonly int $width,
        private readonly int $height,
        private readonly int $colorspace,
        private readonly ?object $owner = null,
    ) {
        if (! self::$engine) {
            self::ok(tvg_engine_init(0), 'tvg_engine_init');
            self::$engine = true;
        }
        $this->canvas = tvg_swcanvas_create(TVG_ENGINE_OPTION_NONE) ?? throw new RenderingException('ThorVG has no software canvas in this build.');
        self::ok(tvg_swcanvas_set_target($this->canvas, $this->target, $pixelsPerRow, $width, $height, $colorspace), 'tvg_swcanvas_set_target');
        $this->shape = tvg_shape_new() ?? throw new RenderingException('ThorVG could not make a shape.');
        self::ok(tvg_canvas_add($this->canvas, $this->shape), 'tvg_canvas_add');
        // a first, empty frame: the canvas is synced, so the viewport may be set
        self::ok(tvg_canvas_draw($this->canvas, false), 'tvg_canvas_draw');
        self::ok(tvg_canvas_sync($this->canvas), 'tvg_canvas_sync');
    }

    /**
     * The ThorVG colorspace that writes $memory's bytes, or null when ThorVG cannot draw into it:
     * it writes 32-bit words, B G R A or R G B A in memory on a little-endian host, top row first,
     * rows a whole number of pixels apart.
     */
    public static function colorspace(PixelMemory $memory): ?int
    {
        try {
            if (Layout::of($memory->format) !== Layout::RGBA8888
                || $memory->format->scan_direction !== ScanDirection::TOP_TO_BOTTOM
                || $memory->stride % 4 !== 0
                || $memory->stride < $memory->width * 4
                || pack('V', 1) !== pack('L', 1)) {
                return null;
            }

            return match (Layout::channelOrder($memory->format)) {
                ChannelOrder::BGRA => TVG_COLORSPACE_ARGB8888,
                ChannelOrder::RGBA => TVG_COLORSPACE_ABGR8888,
                default => null,
            };
        } catch (FramebufferException) {
            return null;    // not a layout at all
        }
    }

    /** A canvas that draws into $memory in place. colorspace($memory) must not be null. */
    public static function over(PixelMemory $memory): self
    {
        $colorspace = self::colorspace($memory) ?? throw new RenderingException('ThorVG cannot draw into this memory.');

        return new self($memory->address, intdiv($memory->stride, 4), $memory->width, $memory->height, $colorspace, $memory->owner);
    }

    /** A cleared $width × $height buffer whose pixel (0, 0) is ($x, $y) of the shapes drawn on it. */
    public static function scratch(int $width, int $height, int $x, int $y): self
    {
        $surface = new self(str_repeat("\0", $width * $height * 4), $width, $width, $height, TVG_COLORSPACE_ARGB8888);
        self::ok(tvg_paint_translate($surface->shape, -$x, -$y), 'tvg_paint_translate');

        return $surface;
    }

    /** The shape, emptied: add its path, then fill(). */
    public function shape(): Tvg_Paint
    {
        self::ok(tvg_shape_reset($this->shape), 'tvg_shape_reset');

        return $this->shape;
    }

    /**
     * The shape filled in $rgba (0xRRGGBBAA, straight) by $rule (a TVG_FILL_RULE_*), inside $clip
     * when one is given, over what the target holds.
     */
    public function fill(int $rule, int $rgba, ?Region $clip = null): void
    {
        if (! is_null($clip)) {
            self::ok(tvg_canvas_set_viewport($this->canvas, $clip->x, $clip->y, $clip->width, $clip->height), 'tvg_canvas_set_viewport');
        }
        self::ok(tvg_shape_set_fill_rule($this->shape, $rule), 'tvg_shape_set_fill_rule');
        self::ok(tvg_shape_set_fill_color($this->shape, ($rgba >> 24) & 0xFF, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF), 'tvg_shape_set_fill_color');
        self::ok(tvg_canvas_update($this->canvas), 'tvg_canvas_update');
        self::ok(tvg_canvas_draw($this->canvas, false), 'tvg_canvas_draw');
        self::ok(tvg_canvas_sync($this->canvas), 'tvg_canvas_sync');
    }

    /**
     * The shape filled with a gradient by $rule, inside $clip, over what the target holds.
     *
     * VelvetGE hands a gradient over as a 256-colour ramp and the matrix that takes a pixel to the
     * gradient's own space, where a linear gradient runs along x from 0 to 1 and a radial one is
     * the unit circle. ThorVG is given exactly that: the ramp as 256 colour stops, the unit line or
     * circle, and the inverse of that matrix as the gradient's transform.
     *
     * @param  string  $ramp  256 colours of 4 bytes, R G B A, straight
     * @param  array{float, float, float, float, float, float}  $toPixels  a, b, c, d, e, f: x' = a·x + c·y + e, y' = b·x + d·y + f
     * @param  int  $spread  a TVG_STROKE_FILL_*
     */
    public function fillGradient(int $rule, string $ramp, bool $radial, int $spread, array $toPixels, ?Region $clip = null): void
    {
        if (! is_null($clip)) {
            self::ok(tvg_canvas_set_viewport($this->canvas, $clip->x, $clip->y, $clip->width, $clip->height), 'tvg_canvas_set_viewport');
        }
        $gradient = ($radial ? tvg_radial_gradient_new() : tvg_linear_gradient_new()) ?? throw new RenderingException('ThorVG could not make a gradient.');
        $radial
            ? self::ok(tvg_radial_gradient_set($gradient, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0), 'tvg_radial_gradient_set')
            : self::ok(tvg_linear_gradient_set($gradient, 0.0, 0.0, 1.0, 0.0), 'tvg_linear_gradient_set');
        self::ok(tvg_gradient_set_color_stops($gradient, self::stops($ramp), 256), 'tvg_gradient_set_color_stops');
        self::ok(tvg_gradient_set_spread($gradient, $spread), 'tvg_gradient_set_spread');
        [$a, $b, $c, $d, $e, $f] = $toPixels;
        self::ok(tvg_gradient_set_transform($gradient, new Tvg_Matrix($a, $c, $e, $b, $d, $f, 0.0, 0.0, 1.0)), 'tvg_gradient_set_transform');

        self::ok(tvg_shape_set_fill_rule($this->shape, $rule), 'tvg_shape_set_fill_rule');
        self::ok(tvg_shape_set_gradient($this->shape, $gradient), 'tvg_shape_set_gradient');   // the shape owns it from here
        self::ok(tvg_canvas_update($this->canvas), 'tvg_canvas_update');
        self::ok(tvg_canvas_draw($this->canvas, false), 'tvg_canvas_draw');
        self::ok(tvg_canvas_sync($this->canvas), 'tvg_canvas_sync');
    }

    /** @return list<Tvg_Color_Stop> the ramp's 256 colours at offsets i / 255; the last few ramps are kept */
    private static function stops(string $ramp): array
    {
        if (isset(self::$ramps[$ramp])) {
            return self::$ramps[$ramp];
        }
        strlen($ramp) === 1024 || throw new RenderingException('A gradient ramp is 256 colours of 4 bytes.');
        $stops = [];
        for ($i = 0; $i < 256; $i++) {
            $stops[] = new Tvg_Color_Stop($i / 255, ord($ramp[4 * $i]), ord($ramp[4 * $i + 1]), ord($ramp[4 * $i + 2]), ord($ramp[4 * $i + 3]));
        }
        if (count(self::$ramps) >= 16) {
            array_shift(self::$ramps);
        }

        return self::$ramps[$ramp] = $stops;
    }

    /** The alpha byte of every pixel, rows packed: a scratch buffer's coverage after an opaque white fill. */
    public function alpha(): string
    {
        return tvg_swcanvas_get_alpha($this->target, $this->pixelsPerRow, $this->width, $this->height, $this->colorspace);
    }

    private static function ok(int $result, string $call): void
    {
        $result === TVG_RESULT_SUCCESS || throw new RenderingException("{$call}() answered {$result}.");
    }
}
