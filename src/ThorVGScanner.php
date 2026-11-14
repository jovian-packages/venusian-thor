<?php

namespace Jovian\Rendering\Thor;

use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\DirectScanner;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Rasterization\MaskScanner;
use Surface\Contracts\Rendering\Rasterization\Scanner;

/**
 * Fills paths with ThorVG's anti-aliased rasteriser, three ways:
 *
 * - fill() draws them straight into a framebuffer's memory, when that memory is 32-bit B G R A or
 *   R G B A rows (ThorVGSurface::colorspace()). ThorVG blends there itself.
 * - mask() answers a path's coverage as bytes, for every other memory: the path is filled in
 *   opaque white on a cleared scratch buffer and tvg_swcanvas_get_alpha() reads the coverage back
 *   in one call; VelvetGE lands it with the store's paintMasks().
 * - path() run-length encodes that same coverage into spans.
 *
 * The other shapes and hard edges are VelvetGE's, through the completing scanner and the driver.
 */
class ThorVGScanner implements DirectScanner, MaskScanner
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
        $coverage = $this->coverage($contours, $rule === FillRule::EVEN_ODD);
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

    /** @param list<mixed> $op [Scanner::PATH, list of flat contours, 0 non-zero | 1 even-odd] */
    public function mask(array $op): ?array
    {
        if (($op[0] ?? null) !== Scanner::PATH) {
            return null;
        }
        $coverage = $this->coverage($op[1], (int) $op[2] === 1);
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
     * Each path drawn into $memory by ThorVG, inside the clip. The region answered for an op is its
     * extents inside the clip: every pixel it changed is in there, and a sliver too thin to cover
     * a pixel may make it larger than the pixels changed.
     *
     * @param  list<list<mixed>>  $ops  PATH ops
     * @param  list<int>  $colours  0xRRGGBBAA
     */
    public function fill(array $ops, array $colours, PixelMemory $memory): array
    {
        $clip = $this->clip->intersect(new Region(0, 0, $memory->width, $memory->height));
        if (is_null($clip) || $clip->isEmpty()) {
            return array_fill(0, count($ops), null);
        }

        $surface = ThorVGSurface::over($memory);
        $touched = [];
        foreach ($ops as $i => $op) {
            $bounds = ($colours[$i] & 0xFF) === 0 ? null : self::trace($surface->shape(), $op[1]);
            $area = $bounds?->intersect($clip);
            if (is_null($area) || $area->isEmpty()) {
                $touched[] = null;

                continue;
            }
            $surface->fill((int) $op[2] === 1 ? TVG_FILL_RULE_EVEN_ODD : TVG_FILL_RULE_NON_ZERO, $colours[$i], $clip);
            $touched[] = $area;
        }

        return $touched;
    }

    /**
     * The path's a8 coverage over its extents inside the clip, rows packed, or null when it covers
     * no pixel there.
     *
     * @param  list<list<float>>  $contours  flat x, y lists, closed implicitly
     * @return array{string, Region}|null
     */
    private function coverage(array $contours, bool $evenOdd): ?array
    {
        $bounds = self::extents($contours);
        $area = $bounds?->intersect($this->clip);
        if (is_null($area) || $area->isEmpty()) {
            return null;
        }

        $rule = $evenOdd ? TVG_FILL_RULE_EVEN_ODD : TVG_FILL_RULE_NON_ZERO;
        $tile = function (int $x, int $y, int $w, int $h) use ($contours, $rule): string {
            $surface = ThorVGSurface::scratch($w, $h, $x, $y);
            self::trace($surface->shape(), $contours);
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
