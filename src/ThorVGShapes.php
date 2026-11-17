<?php

namespace Jovian\Rendering\Thor;

use Surface\Contracts\Rendering\Framebuffers\Region;
use Tvg_Paint;

/**
 * Velvet's shape questions as ThorVG geometry, shared by the scanner and the brush. Each method
 * adds the shape to $shape and answers the fill rule to fill it with and the pixels its extents
 * reach.
 */
final class ThorVGShapes
{
    /** A cubic's control distance for a quarter circle of radius 1. */
    private const float KAPPA = 0.5522847498307936;

    /** @return array{int, Region} */
    public static function ellipse(Tvg_Paint $shape, float $cx, float $cy, float $rx, float $ry): array
    {
        tvg_shape_append_circle($shape, $cx, $cy, $rx, $ry, true);

        return [TVG_FILL_RULE_NON_ZERO, self::box($cx - $rx, $cy - $ry, $cx + $rx, $cy + $ry)];
    }

    /**
     * The band between radii r ± stroke / 2; all of the outer ellipse when the inner one vanishes.
     *
     * @return array{int, Region}
     */
    public static function ring(Tvg_Paint $shape, float $cx, float $cy, float $rx, float $ry, float $stroke): array
    {
        $half = $stroke / 2;
        tvg_shape_append_circle($shape, $cx, $cy, $rx + $half, $ry + $half, true);
        if ($rx - $half > 0 && $ry - $half > 0) {
            tvg_shape_append_circle($shape, $cx, $cy, $rx - $half, $ry - $half, true);
        }

        return [TVG_FILL_RULE_EVEN_ODD, self::box($cx - $rx - $half, $cy - $ry - $half, $cx + $rx + $half, $cy + $ry + $half)];
    }

    /**
     * @param  array<int, float>  $radii  top-left, top-right, bottom-right, bottom-left, already held to the box
     * @return array{int, Region}
     */
    public static function roundRect(Tvg_Paint $shape, float $x0, float $y0, float $x1, float $y1, array $radii): array
    {
        self::addRoundRect($shape, $x0, $y0, $x1, $y1, array_values($radii));

        return [TVG_FILL_RULE_NON_ZERO, self::box($x0, $y0, $x1, $y1)];
    }

    /**
     * The band inside the box, $inset wide: the inner box's radii are max(0, r − inset).
     *
     * @param  array<int, float>  $radii
     * @return array{int, Region}
     */
    public static function roundRing(Tvg_Paint $shape, float $x0, float $y0, float $x1, float $y1, array $radii, float $inset): array
    {
        $r = array_values($radii);
        self::addRoundRect($shape, $x0, $y0, $x1, $y1, $r);
        [$ix0, $iy0, $ix1, $iy1] = [$x0 + $inset, $y0 + $inset, $x1 - $inset, $y1 - $inset];
        if ($ix1 > $ix0 && $iy1 > $iy0) {
            self::addRoundRect($shape, $ix0, $iy0, $ix1, $iy1, array_map(fn (float $v): float => max(0.0, $v - $inset), $r));
        }

        return [TVG_FILL_RULE_EVEN_ODD, self::box($x0, $y0, $x1, $y1)];
    }

    /**
     * Five lines even with no radius, so ThorVG never takes it for its whole-pixel rectangle.
     *
     * @param  list<float>  $r  top-left, top-right, bottom-right, bottom-left: circular corners
     */
    public static function addRoundRect(Tvg_Paint $p, float $x0, float $y0, float $x1, float $y1, array $r): void
    {
        [$tl, $tr, $br, $bl] = $r;
        $k = self::KAPPA;
        tvg_shape_move_to($p, $x0 + $tl, $y0);
        tvg_shape_line_to($p, $x1 - $tr, $y0);
        if ($tr > 0) {
            tvg_shape_cubic_to($p, $x1 - $tr + $tr * $k, $y0, $x1, $y0 + $tr - $tr * $k, $x1, $y0 + $tr);
        }
        tvg_shape_line_to($p, $x1, $y1 - $br);
        if ($br > 0) {
            tvg_shape_cubic_to($p, $x1, $y1 - $br + $br * $k, $x1 - $br + $br * $k, $y1, $x1 - $br, $y1);
        }
        tvg_shape_line_to($p, $x0 + $bl, $y1);
        if ($bl > 0) {
            tvg_shape_cubic_to($p, $x0 + $bl - $bl * $k, $y1, $x0, $y1 - $bl + $bl * $k, $x0, $y1 - $bl);
        }
        tvg_shape_line_to($p, $x0, $y0 + $tl);
        if ($tl > 0) {
            tvg_shape_cubic_to($p, $x0, $y0 + $tl - $tl * $k, $x0 + $tl - $tl * $k, $y0, $x0 + $tl, $y0);
        }
        tvg_shape_close($p);
    }

    /** The pixels a box of coordinates reaches. */
    public static function box(float $x0, float $y0, float $x1, float $y1): Region
    {
        [$left, $top] = [(int) floor($x0), (int) floor($y0)];

        return new Region($left, $top, (int) ceil($x1) - $left, (int) ceil($y1) - $top);
    }
}
