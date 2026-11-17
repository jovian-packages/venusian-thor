<?php

declare(strict_types=1);

use Jovian\Rendering\Thor\ThorVGBrush;
use Jovian\Rendering\Thor\ThorVGPaintingDriver;
use Jovian\Rendering\Thor\ThorVGRasterizationDriver;
use Surface\Contracts\Graphics\StrokeStyle;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\Painter;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\GradientSpread;
use Surface\NutsAndBolts\LinearGradient;
use Surface\NutsAndBolts\Path;
use Surface\NutsAndBolts\RadialGradient;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Painting\PHPLandPaintbrushDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;

/*
 * ThorVG's brush against VelvetGE's native painter. ThorVG draws each shape straight into the
 * frame's memory, so frames differ at shape edges (its coverage) and by its blend rounding: within
 * ThorVGBrush::TOLERANCE where an outline never crosses itself inside a pixel, beyond it only where
 * contours cross inside one. A R G B and A B G R frames are not ThorVG's to draw: VelvetGE paints
 * them and they come out the same bytes. Runs inside topside's suite (AGENTS.md): needs ext-thorvg
 * and ext-fb.
 */

const THOR_W = 120;
const THOR_H = 90;

/** One draw call of $kind, seeded; $count of them over a seeded clear. */
function thorScene(string $kind, int $seed, int $count = 1): Closure
{
    return function (Painter $g) use ($kind, $seed, $count): void {
        mt_srand($seed);
        $n = fn (float $lo, float $hi): float => $lo + ($hi - $lo) * mt_rand() / mt_getrandmax();
        $colour = fn (): Color => Color::rgba(mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 2) ? $n(0.2, 1.0) : 1.0);
        $path = function () use ($n): Path {
            $p = (new Path())->moveTo($n(0, THOR_W), $n(0, THOR_H));
            for ($i = mt_rand(2, 6); $i > 0; $i--) {
                mt_rand(0, 3) ? $p->lineTo($n(-10, THOR_W + 10), $n(-10, THOR_H + 10)) : $p->cubicTo($n(0, THOR_W), $n(0, THOR_H), $n(0, THOR_W), $n(0, THOR_H), $n(0, THOR_W), $n(0, THOR_H));
            }

            return $p->close();
        };
        $stops = fn (): array => [[0.0, $colour()], [$n(0.3, 0.7), $colour()], [1.0, $colour()]];
        $spread = fn (): GradientSpread => [GradientSpread::PAD, GradientSpread::REPEAT, GradientSpread::REFLECT][mt_rand(0, 2)];
        $g->clear(Color::rgb(mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        for ($i = 0; $i < $count; $i++) {
            match ($kind) {
                'fillRect' => $g->fillRect($n(-5, THOR_W), $n(-5, THOR_H), $n(1, 40), $n(1, 40), $colour()),
                'line' => $g->line($n(0, THOR_W), $n(0, THOR_H), $n(0, THOR_W), $n(0, THOR_H), $colour(), $n(1, 7)),
                'fillEllipse' => $g->fillEllipse($n(0, THOR_W), $n(0, THOR_H), $n(1, 40), $n(1, 30), $colour()),
                'strokeEllipse' => $g->strokeEllipse($n(0, THOR_W), $n(0, THOR_H), $n(2, 40), $n(2, 30), $colour(), $n(0.5, 8)),
                'roundRect' => $g->roundRect($n(0, 80), $n(0, 50), $n(4, 60), $n(4, 50), [$n(0, 16), $n(0, 16), $n(0, 16), $n(0, 16)], $colour(), mt_rand(0, 1) ? $colour() : null, $n(0.5, 5)),
                'fill path' => $g->fill($path(), $colour(), mt_rand(0, 1) ? FillRule::NON_ZERO : FillRule::EVEN_ODD),
                'stroke path' => $g->stroke($path(), $colour(), new StrokeStyle(width: $n(0.5, 6))),
                'polyline' => $g->polyline([[$n(0, THOR_W), $n(0, THOR_H)], [$n(0, THOR_W), $n(0, THOR_H)], [$n(0, THOR_W), $n(0, THOR_H)]], $colour(), $n(1, 6), (bool) mt_rand(0, 1)),
                'linear gradient' => $g->fill($path(), new LinearGradient($n(0, THOR_W), $n(0, THOR_H), $n(0, THOR_W), $n(0, THOR_H), $stops(), $spread())),
                'radial gradient' => $g->fill($path(), new RadialGradient($n(0, THOR_W), $n(0, THOR_H), $n(5, 50), $stops(), $spread())),
                'shadow' => $g->shadow($n(0, 80), $n(0, 50), $n(4, 60), $n(4, 40), $n(0, 10), $n(0, 9), $colour(), $n(-3, 3), $n(-3, 3)),
            };
        }
    };
}

/**
 * The scene painted on a fresh 32-bit frame. $thor: through 'thorvg' painting (ThorVGBrush, and
 * ThorVG's rasterizer for what the brush does not take); false: through Lustrous's PHP painter;
 * 'raster': through VelvetGE's painter with no brush and ThorVG's rasterizer.
 *
 * @return array{string, list<Region>, list<array<int, mixed>>} the frame as RGBA8, its damage, and the commands not drawn
 */
function thorFrame(bool|string $thor, Closure $scene, ChannelOrder $order, Edges $edges): array
{
    $record = new \Surface\Rendering\Recording\Drivers\PHPLandRecordingDriver();
    $driver = match ($thor) {
        true => new ThorVGPaintingDriver(null, true, $record),
        false => new PHPLandPaintbrushDriver(new PHPLandRasterizationDriver(), true, $record),
        'raster' => new PHPLandPaintbrushDriver(new ThorVGRasterizationDriver(), true, $record),
    };
    $target = (new CLandFramebufferDriver())->dirty(new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, channel_order: $order), THOR_W, THOR_H);
    $target->beginEpoch();
    $painter = $driver->painter($target, $edges);
    $painter->frame($scene);

    return [$target->flushRegion(new Region(0, 0, THOR_W, THOR_H), FormatSpec::rgba8()), $target->damage(), $painter->undrawn()];
}

/** @return array{int, int, int} worst per-byte distance, pixels that differ, pixels beyond the tolerance */
function thorDistance(string $a, string $b): array
{
    [$worst, $differ, $beyond] = [0, 0, 0];
    for ($i = 0, $len = strlen($a); $i < $len; $i += 4) {
        $d = 0;
        for ($k = 0; $k < 4; $k++) {
            $d = max($d, abs(ord($a[$i + $k]) - ord($b[$i + $k])));
        }
        $worst = max($worst, $d);
        $differ += $d > 0 ? 1 : 0;
        $beyond += $d > ThorVGBrush::TOLERANCE ? 1 : 0;
    }

    return [$worst, $differ, $beyond];
}

function thorNeeds(): void
{
    if (! function_exists('tvg_swcanvas_get_alpha') || ! extension_loaded('fb')) {
        test()->markTestSkipped('ext-thorvg (with tvg_swcanvas_get_alpha) and ext-fb are not both loaded.');
    }
}

dataset('thor orders', [ChannelOrder::RGBA, ChannelOrder::BGRA, ChannelOrder::ARGB, ChannelOrder::ABGR]);

it('paints shapes whose outline never crosses itself within the tolerance of the native painter', function (string $kind, ChannelOrder $order): void {
    thorNeeds();
    foreach (range(1, 12) as $seed) {
        [$a] = thorFrame(false, thorScene($kind, $seed), $order, Edges::ANTIALIASED);
        [$b] = thorFrame(true, thorScene($kind, $seed), $order, Edges::ANTIALIASED);
        expect(thorDistance($a, $b)[0])->toBeLessThanOrEqual(ThorVGBrush::TOLERANCE);
    }
})->with(['fillRect', 'line', 'fillEllipse', 'strokeEllipse', 'roundRect'])->with('thor orders');

it('passes the tolerance only where a path crosses itself inside a pixel', function (string $kind, ChannelOrder $order): void {
    thorNeeds();
    [$differ, $beyond] = [0, 0];
    foreach (range(1, 12) as $seed) {
        [$a] = thorFrame(false, thorScene($kind, $seed), $order, Edges::ANTIALIASED);
        [$b] = thorFrame(true, thorScene($kind, $seed), $order, Edges::ANTIALIASED);
        [, $d, $o] = thorDistance($a, $b);
        [$differ, $beyond] = [$differ + $d, $beyond + $o];
    }
    expect($beyond)->toBeLessThan(max(1, $differ) * 0.02);
})->with(['fill path', 'stroke path', 'polyline', 'linear gradient', 'radial gradient'])->with('thor orders');

it('damages every pixel it changes', function (string $kind): void {
    thorNeeds();
    foreach (range(1, 6) as $seed) {
        [$clear] = thorFrame(true, thorScene($kind, $seed, 0), ChannelOrder::BGRA, Edges::ANTIALIASED);
        [$frame, $damage] = thorFrame(true, thorScene($kind, $seed, 8), ChannelOrder::BGRA, Edges::ANTIALIASED);
        for ($y = 0; $y < THOR_H; $y++) {
            for ($x = 0; $x < THOR_W; $x++) {
                $at = ($y * THOR_W + $x) * 4;
                if (substr($frame, $at, 4) !== substr($clear, $at, 4)) {
                    expect(array_filter($damage, fn (Region $r): bool => $r->contains($x, $y)))->not->toBeEmpty();
                }
            }
        }
    }
})->with(['fillRect', 'fillEllipse', 'roundRect', 'fill path', 'stroke path', 'linear gradient', 'radial gradient']);

it('leaves hard edges and the commands it does not take to the painter, which rasterizes them with ThorVG', function (string $kind, Edges $edges): void {
    thorNeeds();
    foreach (range(1, 4) as $seed) {
        $raster = thorFrame('raster', thorScene($kind, $seed, 6), ChannelOrder::BGRA, $edges);
        $thor = thorFrame(true, thorScene($kind, $seed, 6), ChannelOrder::BGRA, $edges);
        expect($thor[0] === $raster[0])->toBeTrue()
            ->and(array_map(fn (Region $r) => [$r->x, $r->y, $r->width, $r->height], $thor[1]))
            ->toBe(array_map(fn (Region $r) => [$r->x, $r->y, $r->width, $r->height], $raster[1]))
            ->and(count($thor[2]))->toBe(count($raster[2]));
    }
})->with([
    'hard fill path' => ['fill path', Edges::HARD],
    'hard ellipse' => ['fillEllipse', Edges::HARD],
    'hard gradient' => ['linear gradient', Edges::HARD],
    'shadow' => ['shadow', Edges::ANTIALIASED],
]);

it('hands back the shadows ThorVG has no answer for, drawing none of them', function (): void {
    thorNeeds();
    foreach (range(1, 4) as $seed) {
        [$blank] = thorFrame(true, thorScene('shadow', $seed, 0), ChannelOrder::BGRA, Edges::ANTIALIASED);
        [$frame, , $undrawn] = thorFrame(true, thorScene('shadow', $seed, 6), ChannelOrder::BGRA, Edges::ANTIALIASED);
        expect($frame === $blank)->toBeTrue()
            ->and($undrawn)->not->toBeEmpty()
            ->and(array_unique(array_column($undrawn, 0)))->toBe(['shadow']);
    }
});

it('takes only the anti-aliased shapes it draws', function (): void {
    thorNeeds();
    $brush = new ThorVGBrush();
    $clip = new Region(0, 0, 10, 10);

    expect($brush->takes(['path', [], FillRule::NON_ZERO, 0, $clip], Edges::ANTIALIASED))->toBeTrue()
        ->and($brush->takes(['path', [], FillRule::NON_ZERO, 0, $clip], Edges::HARD))->toBeFalse()
        ->and($brush->takes(['shadow', 0, 0, 1, 1, 0, 0, 0, $clip], Edges::ANTIALIASED))->toBeFalse()
        ->and($brush->takes(['clear', 0], Edges::ANTIALIASED))->toBeFalse();
});

it('draws into B G R A and R G B A memory and no other', function (ChannelOrder $order, bool $draws): void {
    thorNeeds();
    $memory = (new CLandFramebufferDriver())->full(new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, channel_order: $order), 4, 4)->memory();

    expect((new ThorVGBrush())->drawsInto($memory))->toBe($draws);
})->with([[ChannelOrder::RGBA, true], [ChannelOrder::BGRA, true], [ChannelOrder::ARGB, false], [ChannelOrder::ABGR, false]]);

it('leaves a gradient whose matrix cannot be turned round to VelvetGE', function (): void {
    thorNeeds();
    $clip = new Region(0, 0, 10, 10);
    $gradient = fn (float $a, float $d) => ['kind' => 'linear', 'ramp' => str_repeat("\xFF", 1024), 'spread' => GradientSpread::PAD, 'inverse' => new \Surface\NutsAndBolts\Affine($a, 0.0, 0.0, $d, 0.0, 0.0)];

    expect((new ThorVGBrush())->takes(['gradpath', [], FillRule::NON_ZERO, $gradient(0.1, 0.1), $clip], Edges::ANTIALIASED))->toBeTrue()
        ->and((new ThorVGBrush())->takes(['gradpath', [], FillRule::NON_ZERO, $gradient(0.1, 0.0), $clip], Edges::ANTIALIASED))->toBeFalse();
});
