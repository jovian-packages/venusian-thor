<?php

namespace Jovian\Rendering\Thor\Stroking;

use Surface\Contracts\Rendering\Rasterization\StrokeCap;
use Surface\Contracts\Rendering\Rasterization\StrokeGeometry;
use Surface\Contracts\Rendering\Rasterization\StrokeJoin;
use Surface\Contracts\Rendering\RenderingException;
use Surface\NutsAndBolts\Path;

/**
 * ThorVG's stroker: the polyline as a ThorVG shape with its stroke settings, and the outline ThorVG's
 * own CPU stroker computes for it (tvg_shape_get_stroke_outline(), an ext-thorvg addition). Round
 * joins and caps come back as curves and are flattened to contours within 0.1 px.
 *
 * ThorVG's dasher is internal to its CPU engine and has no way out, so this is not a DashGeometry:
 * a dashed stroke comes back undrawn. A zero-length stroke is ThorVG's, so the direction it is
 * given is not used.
 */
final class ThorVGStroker implements StrokeGeometry
{
    /** How far a flattened round join or cap strays from its arc, in pixels. */
    private const float FLATNESS = 0.1;

    public function __construct()
    {
        function_exists('tvg_shape_get_stroke_outline') || throw new RenderingException('ext-thorvg with tvg_shape_get_stroke_outline() is not loaded in this PHP ('.PHP_BINARY.').');
        static $engine = false;
        if (! $engine) {
            tvg_engine_init(0);
            $engine = true;
        }
    }

    public function outline(array $points, float $stroke, bool $closed, StrokeCap $cap = StrokeCap::BUTT, StrokeJoin $join = StrokeJoin::MITER, float $miterLimit = self::MITER_LIMIT, ?array $direction = null): array
    {
        if (! ($stroke > 0) || $points === []) {
            return [];
        }
        $shape = tvg_shape_new() ?? throw new RenderingException('ThorVG could not make a shape.');
        foreach (array_values($points) as $i => [$x, $y]) {
            $i === 0 ? tvg_shape_move_to($shape, (float) $x, (float) $y) : tvg_shape_line_to($shape, (float) $x, (float) $y);
        }
        if ($closed) {
            tvg_shape_close($shape);
        }
        tvg_shape_set_stroke_width($shape, $stroke);
        tvg_shape_set_stroke_cap($shape, match ($cap) {
            StrokeCap::BUTT => TVG_STROKE_CAP_BUTT,
            StrokeCap::ROUND => TVG_STROKE_CAP_ROUND,
            StrokeCap::SQUARE => TVG_STROKE_CAP_SQUARE,
        });
        tvg_shape_set_stroke_join($shape, match ($join) {
            StrokeJoin::MITER => TVG_STROKE_JOIN_MITER,
            StrokeJoin::ROUND => TVG_STROKE_JOIN_ROUND,
            StrokeJoin::BEVEL => TVG_STROKE_JOIN_BEVEL,
        });
        tvg_shape_set_stroke_miterlimit($shape, $miterLimit);

        $ok = tvg_shape_get_stroke_outline($shape, $commands, $count, $vertices, $vertexCount);
        $ok === TVG_RESULT_SUCCESS || throw new RenderingException("ThorVG could not stroke the polyline (result {$ok}).");

        $path = new Path();
        $v = 0;
        foreach ($commands ?? [] as $command) {
            switch ($command) {
                case TVG_PATH_COMMAND_MOVE_TO:
                    $path->moveTo($vertices[$v]->x, $vertices[$v]->y);
                    $v++;
                    break;
                case TVG_PATH_COMMAND_LINE_TO:
                    $path->lineTo($vertices[$v]->x, $vertices[$v]->y);
                    $v++;
                    break;
                case TVG_PATH_COMMAND_CUBIC_TO:
                    $path->cubicTo($vertices[$v]->x, $vertices[$v]->y, $vertices[$v + 1]->x, $vertices[$v + 1]->y, $vertices[$v + 2]->x, $vertices[$v + 2]->y);
                    $v += 3;
                    break;
                case TVG_PATH_COMMAND_CLOSE:
                    $path->close();
                    break;
            }
        }

        $contours = [];
        foreach ($path->flatten(self::FLATNESS) as ['points' => $ring]) {
            if (count($ring) >= 3) {
                $contours[] = array_merge(...$ring);
            }
        }

        return $contours;
    }
}
