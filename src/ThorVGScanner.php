<?php

namespace Jovian\Rendering\Thor;

use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\DirectScanner;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Rasterization\MaskScanner;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use Surface\Contracts\Rendering\Rasterization\ShapeScanner;

/**
 * Fills paths, ellipses, rings, round rects and round rings with ThorVG's rasteriser, as ThorVG
 * geometry (ThorVGShapes), three ways:
 *
 * - fill() draws them straight into a framebuffer's memory, when that memory is 32-bit B G R A or
 *   R G B A rows (ThorVGSurface::colorspace()). ThorVG blends there itself.
 * - mask() answers a shape's coverage as bytes, for every other memory: the path is filled in
 *   opaque white on a cleared scratch buffer and tvg_swcanvas_get_alpha() reads the coverage back
 *   in one call; VelvetGE lands it with the store's paintMasks().
 * - path(), ellipse(), ring(), roundRect() and roundRing() run-length encode that same coverage
 *   into spans.
 *
 * Both edges are ThorVG's: anti-aliased by default, hard with its aliased option. ThorVG has no
 * shadow and no one-pixel line; Velvet hands those back undrawn.
 */
class ThorVGScanner implements DirectScanner, MaskScanner, ShapeScanner
{
    /**
     * Greatest coverage difference from VelvetGE's scanner at any pixel of a simple shape (measured
     * 2026-11-13 over 100 random rects, triangles, rect strokes and lines each). Where a path's
     * contours cross inside one pixel the two rasterisers resolve the overlap differently and a
     * pixel can differ by up to 197; those are under 0.5% of a shape's pixels, and areas agree
     * within 0.4%.
     */
    public const int TOLERANCE = 11;

    /** Scratch buffers are at most this wide and high (4096² is 64 MiB). */
    private const int TILE = 4096;

    public function __construct(
        private readonly Region $clip,
        private readonly Edges $edges,
    ) {}

    public function clip(): Region
    {
        return $this->clip;
    }

    public function edges(): Edges
    {
        return $this->edges;
    }

    public function path(array $contours, FillRule $rule): string
    {
        return $this->spans([Scanner::PATH, $contours, $rule === FillRule::EVEN_ODD ? 1 : 0]);
    }

    public function ellipse(float $cx, float $cy, float $rx, float $ry): string
    {
        return $this->spans([Scanner::ELLIPSE, $cx, $cy, $rx, $ry]);
    }

    public function ring(float $cx, float $cy, float $rx, float $ry, float $stroke): string
    {
        return $this->spans([Scanner::RING, $cx, $cy, $rx, $ry, $stroke]);
    }

    public function roundRect(float $x0, float $y0, float $x1, float $y1, array $radii): string
    {
        return $this->spans([Scanner::ROUND_RECT, $x0, $y0, $x1, $y1, $radii]);
    }

    public function roundRing(float $x0, float $y0, float $x1, float $y1, array $radii, float $inset): string
    {
        return $this->spans([Scanner::ROUND_RING, $x0, $y0, $x1, $y1, $radii, $inset]);
    }

    /** The op's coverage run-length encoded into spans. */
    private function spans(array $op): string
    {
        $coverage = $this->coverage($op);
        if (is_null($coverage)) {
            return '';
        }
        [$a8, $area] = $coverage;
        $spans = '';
        for ($r = 0; $r < $area->height; $r++) {
            $row = substr($a8, $r * $area->width, $area->width);
            for ($o = 0; $o < $area->width;) {
                $byte = $row[$o];
                $run = strspn($row, $byte, $o);
                if ($byte !== "\0") {
                    $spans .= pack('vvvC', $area->y + $r, $area->x + $o, $run, ord($byte));
                }
                $o += $run;
            }
        }

        return $spans;
    }

    /** @param list<mixed> $op a PATH, ELLIPSE, RING, ROUND_RECT or ROUND_RING op */
    public function mask(array $op): ?array
    {
        $coverage = $this->coverage($op);
        if (is_null($coverage)) {
            return null;
        }
        [$a8, $area] = $coverage;

        return [$a8, $area->width, $area->x, $area->y];
    }

    public function drawsInto(PixelMemory $memory): bool
    {
        return ! is_null(ThorVGSurface::colorspace($memory));
    }

    /**
     * Each shape drawn into $memory by ThorVG, inside the clip. The region answered for an op is its
     * extents inside the clip: every pixel it changed is in there, and a sliver too thin to cover
     * a pixel may make it larger than the pixels changed.
     *
     * @param  list<list<mixed>>  $ops  PATH, ELLIPSE, RING, ROUND_RECT or ROUND_RING ops
     * @param  list<int>  $colours  0xRRGGBBAA
     */
    public function fill(array $ops, array $colours, PixelMemory $memory): array
    {
        $clip = $this->clip->intersect(new Region(0, 0, $memory->width, $memory->height));
        if (is_null($clip) || $clip->isEmpty()) {
            return array_fill(0, count($ops), null);
        }

        $surface = ThorVGSurface::over($memory, $this->edges === Edges::HARD);
        $touched = [];
        foreach ($ops as $i => $op) {
            [$rule, $bounds] = ($colours[$i] & 0xFF) === 0 ? [null, null] : self::shape($surface->shape(), $op);
            $area = $bounds?->intersect($clip);
            if (is_null($area) || $area->isEmpty()) {
                $touched[] = null;

                continue;
            }
            $surface->fill($rule, $colours[$i], $clip);
            $touched[] = $area;
        }

        return $touched;
    }

    /**
     * The op's a8 coverage over its extents inside the clip, rows packed, or null when it covers
     * no pixel there.
     *
     * @param  list<mixed>  $op
     * @return array{string, Region}|null
     */
    private function coverage(array $op): ?array
    {
        $bounds = self::bounds($op);
        $area = $bounds?->intersect($this->clip);
        if (is_null($area) || $area->isEmpty()) {
            return null;
        }

        $tile = function (int $x, int $y, int $w, int $h) use ($op): string {
            $surface = ThorVGSurface::scratch($w, $h, $x, $y, $this->edges === Edges::HARD);
            [$rule] = self::shape($surface->shape(), $op);
            $surface->fill($rule, 0xFFFFFFFF);

            return $surface->alpha();
        };

        if ($area->width <= self::TILE && $area->height <= self::TILE) {
            $a8 = $tile($area->x, $area->y, $area->width, $area->height);
        } else {
            // Larger than one scratch buffer: tiles stitched row by row into one packed plane.
            $a8 = '';
            for ($y = $area->y; $y < $area->bottom(); $y += self::TILE) {
                $h = min(self::TILE, $area->bottom() - $y);
                $rows = array_fill(0, $h, '');
                for ($x = $area->x; $x < $area->right(); $x += self::TILE) {
                    $w = min(self::TILE, $area->right() - $x);
                    foreach (str_split($tile($x, $y, $w, $h), $w) as $r => $row) {
                        $rows[$r] .= $row;
                    }
                }
                $a8 .= implode('', $rows);
            }
        }

        return strspn($a8, "\0") === strlen($a8) ? null : [$a8, $area];
    }

    /**
     * The op added to $shape as ThorVG geometry. Answers the fill rule to fill it with and the
     * pixels its extents reach (null when it reaches none).
     *
     * @param  list<mixed>  $op
     * @return array{int, ?Region}
     */
    public static function shape(\Tvg_Paint $shape, array $op): array
    {
        return match ($op[0]) {
            Scanner::PATH => [(int) $op[2] === 1 ? TVG_FILL_RULE_EVEN_ODD : TVG_FILL_RULE_NON_ZERO, self::trace($shape, $op[1])],
            Scanner::ELLIPSE => ThorVGShapes::ellipse($shape, $op[1], $op[2], $op[3], $op[4]),
            Scanner::RING => ThorVGShapes::ring($shape, $op[1], $op[2], $op[3], $op[4], $op[5]),
            Scanner::ROUND_RECT => ThorVGShapes::roundRect($shape, $op[1], $op[2], $op[3], $op[4], $op[5]),
            Scanner::ROUND_RING => ThorVGShapes::roundRing($shape, $op[1], $op[2], $op[3], $op[4], $op[5], $op[6]),
        };
    }

    /**
     * The pixels the op's extents reach, without drawing it.
     *
     * @param  list<mixed>  $op
     */
    private static function bounds(array $op): ?Region
    {
        return match ($op[0]) {
            Scanner::PATH => self::extents($op[1]),
            Scanner::ELLIPSE => ThorVGShapes::box($op[1] - $op[3], $op[2] - $op[4], $op[1] + $op[3], $op[2] + $op[4]),
            Scanner::RING => ThorVGShapes::box($op[1] - $op[3] - $op[5] / 2, $op[2] - $op[4] - $op[5] / 2, $op[1] + $op[3] + $op[5] / 2, $op[2] + $op[4] + $op[5] / 2),
            Scanner::ROUND_RECT, Scanner::ROUND_RING => ThorVGShapes::box($op[1], $op[2], $op[3], $op[4]),
        };
    }

    /**
     * The contours added to $shape, each closed. Answers the pixels their extents reach, or null
     * when there is no contour of two points.
     *
     * A four-point contour is given a fifth point, halfway along its first edge. ThorVG fills a
     * path of exactly four lines that is an axis-aligned rectangle on whole pixels, with no
     * anti-aliasing (its "fast track", tvgSwShape.cpp _axisAlignedRect()); the extra point leaves
     * the outline as it is and sends it through the anti-aliasing rasteriser.
     *
     * @param  list<list<float>>  $contours
     */
    public static function trace(\Tvg_Paint $shape, array $contours): ?Region
    {
        foreach ($contours as $flat) {
            $n = count($flat);
            if ($n < 4) {
                continue;
            }
            tvg_shape_move_to($shape, $flat[0], $flat[1]);
            if ($n === 8) {
                tvg_shape_line_to($shape, ($flat[0] + $flat[2]) / 2, ($flat[1] + $flat[3]) / 2);
            }
            for ($i = 2; $i < $n; $i += 2) {
                tvg_shape_line_to($shape, $flat[$i], $flat[$i + 1]);
            }
            tvg_shape_close($shape);
        }

        return self::extents($contours);
    }

    /** The pixels the contours' extents reach, or null when there is no contour of two points. */
    private static function extents(array $contours): ?Region
    {
        [$x0, $y0, $x1, $y1] = [INF, INF, -INF, -INF];
        foreach ($contours as $flat) {
            $n = count($flat);
            if ($n < 4) {
                continue;
            }
            for ($i = 0; $i < $n; $i += 2) {
                $x0 = min($x0, $flat[$i]);
                $x1 = max($x1, $flat[$i]);
                $y0 = min($y0, $flat[$i + 1]);
                $y1 = max($y1, $flat[$i + 1]);
            }
        }
        if ($x0 === INF) {
            return null;
        }
        [$left, $top] = [(int) floor($x0), (int) floor($y0)];

        return new Region($left, $top, (int) ceil($x1) - $left, (int) ceil($y1) - $top);
    }
}
