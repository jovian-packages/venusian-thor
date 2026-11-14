<?php

namespace Jovian\Rendering\Thor;

use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\Brush;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use Surface\Contracts\Rendering\RenderingException;
use Surface\NutsAndBolts\GradientSpread;
use Surface\Rendering\Rasterization\Rasterizer;
use Surface\VelvetGE\Drivers\Rasterization\PHPLandRasterizationDriver;
use Tvg_Paint;

/**
 * ThorVG's brush: each anti-aliased shape is drawn by ThorVG straight into the framebuffer's
 * memory, where that memory is 32-bit B G R A or R G B A rows (ThorVGSurface::colorspace()).
 *
 * Shapes are prepared exactly as the rasterizer prepares them (Rasterizer::lower()): radii held,
 * a stroke turned into its outline. The brush takes path, polyline, ellipse, ring, roundrect and
 * roundring commands in one colour, and gradpath with ThorVG's own gradient. Hard edges, clear,
 * shadow, glass, layer, image, glyphs and spans are VelvetGE's painter's, as is every frame in
 * another layout.
 */
final class ThorVGBrush implements Brush
{
    /**
     * Worst per-byte distance from VelvetGE's native painter, one anti-aliased shape at a time
     * (measured 2026-11-13 over 25 seeds a kind, R G B A and B G R A frames): rects 4, lines 6,
     * ellipses 19, round rects 31, rings 34. Where a path's own contours cross inside a pixel
     * (self-crossing fills, stroke joins, polylines, gradient paths) the two rasterisers resolve
     * the overlap differently and a pixel can differ by up to 170: under 0.4% of the pixels that
     * differ at all. Inside a gradient 99.8% of differing pixels are within 8.
     */
    public const int TOLERANCE = 40;

    /** The commands painted, and where each holds its clip. */
    private const array SHAPES = ['path' => 4, 'gradpath' => 4, 'polyline' => 5, 'ellipse' => 6, 'ring' => 7, 'roundrect' => 7, 'roundring' => 8];

    /** A cubic's control distance for a quarter circle of radius 1. */
    private const float KAPPA = 0.5522847498307936;

    /** @var array<string, Rasterizer> by clip: what lowers a command's shape */
    private array $lowering = [];

    public function drawsInto(PixelMemory $memory): bool
    {
        return ! is_null(ThorVGSurface::colorspace($memory));
    }

    /** Anti-aliased shapes; a gradient only when its matrix can be turned round (ThorVG is given the inverse). */
    public function takes(array $command, Edges $edges): bool
    {
        if ($edges !== Edges::ANTIALIASED || ! isset(self::SHAPES[$command[0] ?? null])) {
            return false;
        }
        if ($command[0] !== 'gradpath') {
            return true;
        }
        $m = $command[3]['inverse'];

        return abs($m->a * $m->d - $m->b * $m->c) > 1e-12;
    }

    public function paint(array $commands, Region $rows, PixelMemory $memory, Edges $edges): array
    {
        $surface = ThorVGSurface::over($memory);
        $frame = new Region(0, 0, $memory->width, $memory->height);
        $touched = [];
        foreach ($commands as $command) {
            $cut = $command[self::SHAPES[$command[0]]]->intersect($rows)?->intersect($frame);
            $touched[] = is_null($cut) || $cut->isEmpty() ? null : $this->command($command, $cut, $edges, $surface);
        }

        return $touched;
    }

    /** One command inside $cut, drawn by ThorVG. Answers its extents inside $cut, or null when it draws nothing. */
    private function command(array $command, Region $cut, Edges $edges, ThorVGSurface $surface): ?Region
    {
        $rasterizer = $this->lowering["{$cut->x},{$cut->y},{$cut->width},{$cut->height}"] ??= (new PHPLandRasterizationDriver())->rasterizer($cut, $edges);
        $gradient = $command[0] === 'gradpath';
        [$ops, $colours] = $rasterizer->lower([$gradient ? ['path', $command[1], $command[2], 0xFF, $cut] : $command]);
        if ($ops === [] || (! $gradient && ($colours[0] & 0xFF) === 0)) {
            return null;
        }

        [$rule, $bounds] = $this->shape($surface->shape(), $ops[0]);
        $area = $bounds?->intersect($cut);
        if (is_null($area) || $area->isEmpty()) {
            return null;
        }
        if (! $gradient) {
            $surface->fill($rule, $colours[0], $cut);

            return $area;
        }

        // the command's matrix takes a pixel to the gradient's space; ThorVG wants the way back
        $g = $command[3];
        $m = $g['inverse'];
        $det = $m->a * $m->d - $m->b * $m->c;
        $surface->fillGradient($rule, $g['ramp'], $g['kind'] === 'radial', match ($g['spread']) {
            GradientSpread::PAD => TVG_STROKE_FILL_PAD,
            GradientSpread::REPEAT => TVG_STROKE_FILL_REPEAT,
            GradientSpread::REFLECT => TVG_STROKE_FILL_REFLECT,
        }, [$m->d / $det, -$m->b / $det, -$m->c / $det, $m->a / $det, ($m->c * $m->f - $m->d * $m->e) / $det, ($m->b * $m->e - $m->a * $m->f) / $det], $cut);

        return $area;
    }

    /**
     * The scanner op added to $shape.
     *
     * @return array{int, Region|null} the fill rule it is filled with, and the pixels its extents reach
     */
    private function shape(Tvg_Paint $shape, array $op): array
    {
        return match ($op[0]) {
            Scanner::PATH => [(int) $op[2] === 1 ? TVG_FILL_RULE_EVEN_ODD : TVG_FILL_RULE_NON_ZERO, ThorVGScanner::trace($shape, $op[1])],
            Scanner::ELLIPSE => $this->ellipse($shape, $op[1], $op[2], $op[3], $op[4]),
            Scanner::RING => $this->ring($shape, $op[1], $op[2], $op[3], $op[4], $op[5]),
            Scanner::ROUND_RECT => $this->roundRect($shape, $op[1], $op[2], $op[3], $op[4], $op[5]),
            Scanner::ROUND_RING => $this->roundRing($shape, $op[1], $op[2], $op[3], $op[4], $op[5], $op[6]),
            default => throw new RenderingException("ThorVGBrush has no shape for scanner op {$op[0]}."),
        };
    }

    private function ellipse(Tvg_Paint $shape, float $cx, float $cy, float $rx, float $ry): array
    {
        tvg_shape_append_circle($shape, $cx, $cy, $rx, $ry, true);

        return [TVG_FILL_RULE_NON_ZERO, self::box($cx - $rx, $cy - $ry, $cx + $rx, $cy + $ry)];
    }

    /** The band between radii r ± stroke / 2; all of the outer ellipse when the inner one vanishes. */
    private function ring(Tvg_Paint $shape, float $cx, float $cy, float $rx, float $ry, float $stroke): array
    {
        $half = $stroke / 2;
        tvg_shape_append_circle($shape, $cx, $cy, $rx + $half, $ry + $half, true);
        if ($rx - $half > 0 && $ry - $half > 0) {
            tvg_shape_append_circle($shape, $cx, $cy, $rx - $half, $ry - $half, true);
        }

        return [TVG_FILL_RULE_EVEN_ODD, self::box($cx - $rx - $half, $cy - $ry - $half, $cx + $rx + $half, $cy + $ry + $half)];
    }

    /** @param array{float, float, float, float} $radii top-left, top-right, bottom-right, bottom-left, already held to the box */
    private function roundRect(Tvg_Paint $shape, float $x0, float $y0, float $x1, float $y1, array $radii): array
    {
        $this->addRoundRect($shape, $x0, $y0, $x1, $y1, array_values($radii));

        return [TVG_FILL_RULE_NON_ZERO, self::box($x0, $y0, $x1, $y1)];
    }

    /** The band inside the box, $inset wide: the inner box's radii are max(0, r − inset). */
    private function roundRing(Tvg_Paint $shape, float $x0, float $y0, float $x1, float $y1, array $radii, float $inset): array
    {
        $r = array_values($radii);
        $this->addRoundRect($shape, $x0, $y0, $x1, $y1, $r);
        [$ix0, $iy0, $ix1, $iy1] = [$x0 + $inset, $y0 + $inset, $x1 - $inset, $y1 - $inset];
        if ($ix1 > $ix0 && $iy1 > $iy0) {
            $this->addRoundRect($shape, $ix0, $iy0, $ix1, $iy1, array_map(fn (float $v): float => max(0.0, $v - $inset), $r));
        }

        return [TVG_FILL_RULE_EVEN_ODD, self::box($x0, $y0, $x1, $y1)];
    }

    /**
     * Five lines even with no radius, so ThorVG never takes it for its whole-pixel rectangle.
     *
     * @param  list<float>  $r  top-left, top-right, bottom-right, bottom-left: circular corners
     */
    private function addRoundRect(Tvg_Paint $p, float $x0, float $y0, float $x1, float $y1, array $r): void
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
    private static function box(float $x0, float $y0, float $x1, float $y1): Region
    {
        [$left, $top] = [(int) floor($x0), (int) floor($y0)];

        return new Region($left, $top, (int) ceil($x1) - $left, (int) ceil($y1) - $top);
    }
}
