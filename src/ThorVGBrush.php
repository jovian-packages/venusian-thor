<?php

namespace Jovian\Rendering\Thor;

use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\Brush;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\NutsAndBolts\GradientSpread;
use VelvetGE\Rasterization\Rasterizer;

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
        $rasterizer = $this->lowering["{$cut->x},{$cut->y},{$cut->width},{$cut->height}"] ??= (new ThorVGRasterizationDriver())->rasterizer($cut, $edges);
        $gradient = $command[0] === 'gradpath';
        [$ops, $colours] = $rasterizer->lower([$gradient ? ['path', $command[1], $command[2], 0xFF, $cut] : $command]);
        if ($ops === [] || (! $gradient && ($colours[0] & 0xFF) === 0)) {
            return null;
        }

        [$rule, $bounds] = ThorVGScanner::shape($surface->shape(), $ops[0]);
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
}
