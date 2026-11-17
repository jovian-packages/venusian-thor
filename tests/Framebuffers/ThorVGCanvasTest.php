<?php

use Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Rendering\Thor\Framebuffers\ThorVGCanvas;
use Jovian\Rendering\Thor\Framebuffers\ThorVGFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\Spans;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\GradientSpread;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\EffectsFramebuffer;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;

/*
 * A ThorVG canvas held to lustrous-php, the reference, in RGBA8 and BGRA8. Byte moves match
 * exactly; spans, masks, gradients and plain moves of another framebuffer are ThorVG's blends,
 * within TOLERANCE (5% of 255).
 *
 * Measured and left as ThorVG's: a scaled or turned framebuffer is drawn by ThorVG's own image
 * paths (a scaler for axis-aligned scales, a two-triangle texture mapper with soft edges for any
 * turn), which sample other source pixels than lustrous-php's exact inverse mapping: up to ~199
 * per byte. Those cases are held to what ThorVG does promise: nothing outside the clip, the
 * coverage or the footprint changes, opacity 0 changes nothing, and the footprint is drawn.
 */

const TW = 37;
const TH = 23;

dataset('thorvg formats', [
    'rgba8' => [FormatSpec::rgba8()],
    'bgra8' => [FormatSpec::bgra8()],
]);

/** @return array{ThorVGCanvas, FullFramebuffer} */
function tvboth(FormatSpec $spec, int $w = TW, int $h = TH, ?string $rgba8 = null): array
{
    $pair = [new ThorVGCanvas($spec, $w, $h), new FullFramebuffer(new PHPLandFramebufferDriver(), $spec, $w, $h)];
    if (! is_null($rgba8)) {
        foreach ($pair as $frame) {
            $frame->writeRgba8($rgba8, $w, $h);
        }
    }

    return $pair;
}

function tvfurthest(string $a, string $b): int
{
    expect(strlen($a))->toBe(strlen($b));
    $most = 0;
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $most = max($most, abs(ord($a[$i]) - ord($b[$i])));
    }

    return $most;
}

/** RGBA8 pixel indices whose bytes differ between two read-outs. */
function tvchanged(string $before, string $after): array
{
    $out = [];
    for ($i = 0, $n = intdiv(strlen($before), 4); $i < $n; $i++) {
        if (substr($before, $i * 4, 4) !== substr($after, $i * 4, 4)) {
            $out[] = $i;
        }
    }

    return $out;
}

beforeEach(fn () => mt_srand(7));

it('is the core plus compositing and scrolling, and no effects', function () {
    $canvas = new ThorVGCanvas(FormatSpec::rgba8(), 4, 4);

    expect($canvas)->toBeInstanceOf(InstanceThatManagesAFramebuffer::class)
        ->and($canvas)->toBeInstanceOf(CompositingFramebuffer::class)
        ->and($canvas)->toBeInstanceOf(ScrollingFramebuffer::class)
        ->and($canvas)->not->toBeInstanceOf(EffectsFramebuffer::class);
});

it('refuses every format but RGBA8 and BGRA8', function (FormatSpec $spec) {
    new ThorVGCanvas($spec, 8, 2);
})->with([
    'mono' => [new FormatSpec(PixelFormat::MONO_HORIZONTAL, BitDepth::B1)],
    'rgb888' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24)],
    'rgb565' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B16)],
])->throws(FramebufferException::class);

it('is made by the thorvg driver', function (FormatSpec $spec) {
    $driver = new ThorVGFramebufferDriver();

    expect($driver->driver())->toBe('thorvg')
        ->and($driver->make($spec, 5, 3))->toBeInstanceOf(ThorVGCanvas::class);
})->with('thorvg formats');

it('holds pixel values as lustrous-php does', function (FormatSpec $spec) {
    [$t, $l] = tvboth($spec);
    for ($i = 0; $i < 50; $i++) {
        [$x, $y, $v] = [mt_rand(0, TW - 1), mt_rand(0, TH - 1), mt_rand(0, 0xFFFFFFFF)];
        $t->setPixel($x, $y, $v);
        $l->setPixel($x, $y, $v);
        expect($t->getPixel($x, $y))->toBe($l->getPixel($x, $y));
    }

    expect($t->dump())->toBe($l->dump());
})->with('thorvg formats');

it('fills, writes segments and clears to the same bytes', function (FormatSpec $spec) {
    [$t, $l] = tvboth($spec);
    foreach ([$t, $l] as $frame) {
        mt_srand(1);
        $frame->fill(mt_rand(0, 0xFFFFFFFF))->setSegment(30, 18, 20, 20, mt_rand(0, 0xFFFFFFFF))->setPixels([[0, 0, mt_rand(0, 0xFFFFFFFF)], [3, 4, mt_rand(0, 0xFFFFFFFF)]])->setRegion([[5, 5], [6, 7]], mt_rand(0, 0xFFFFFFFF));
    }
    expect($t->dump())->toBe($l->dump());

    $t->clear();
    $l->clear();
    expect($t->dump())->toBe($l->dump());
})->with('thorvg formats');

it('takes RGBA8 in, and reads it out, as lustrous-php does', function (FormatSpec $spec) {
    [$t, $l] = tvboth($spec, rgba8: random_bytes(TW * TH * 4));
    $region = new Region(3, 2, 11, 9);

    expect($t->dump())->toBe($l->dump())
        ->and($t->toRgba8())->toBe($l->toRgba8())
        ->and($t->flush(FormatSpec::bgra8()))->toBe($l->flush(FormatSpec::bgra8()))
        ->and($t->flushRegion($region, FormatSpec::rgba8()))->toBe($l->flushRegion($region, FormatSpec::rgba8()));
})->with('thorvg formats');

it('scrolls to the same bytes', function (FormatSpec $spec) {
    [$t, $l] = tvboth($spec, rgba8: random_bytes(TW * TH * 4));
    foreach ([[3, -2], [-5, 4], [0, 7]] as [$dx, $dy]) {
        $t->scroll(new Region(2, 2, 30, 18), $dx, $dy);
        $l->scroll(new Region(2, 2, 30, 18), $dx, $dy);
    }

    expect($t->dump())->toBe($l->dump());
})->with('thorvg formats');

it('blits between canvases, and to and from Lustrous, without changing a byte', function (FormatSpec $spec) {
    [$t, $l] = tvboth($spec, rgba8: random_bytes(TW * TH * 4));
    $copy = new ThorVGCanvas($spec, TW, TH);
    $lustrous = new FullFramebuffer(new PHPLandFramebufferDriver(), $spec, TW, TH);

    $t->blitTo($copy);
    $t->blitTo($lustrous);
    expect($copy->dump())->toBe($t->dump())
        ->and($lustrous->dump())->toBe($l->dump());

    $back = new ThorVGCanvas($spec, TW, TH);
    $back->blitFrom($lustrous);
    expect($back->dump())->toBe($l->dump());
})->with('thorvg formats');

it('blends spans and masks within its tolerance of lustrous-php', function (FormatSpec $spec) {
    for ($n = 0; $n < 30; $n++) {
        $background = random_bytes(TW * TH * 4);
        $colour = mt_rand(0, 0xFFFFFFFF);

        $spans = '';
        for ($y = 0; $y < TH; $y += mt_rand(1, 2)) {
            for ($x = mt_rand(0, 3); $x < TW - 1; $x += mt_rand(2, 9)) {
                $length = mt_rand(1, min(6, TW - $x));
                $spans .= Spans::pack($y, $x, $length, mt_rand(1, 255));
                $x += $length;
            }
        }
        [$t, $l] = tvboth($spec, rgba8: $background);
        $t->paintSpans($spans, $colour);
        $l->paintSpans($spans, $colour);
        expect(tvfurthest($t->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(ThorVGCanvas::TOLERANCE);

        $masks = [[random_bytes(10 * 6), 10, mt_rand(-3, TW - 5), mt_rand(-3, TH - 3)], [random_bytes(4 * 9), 4, mt_rand(0, TW - 4), mt_rand(0, TH - 9)]];
        [$t, $l] = tvboth($spec, rgba8: $background);
        $t->paintMasks($masks, $colour, new Region(1, 1, TW - 2, TH - 2));
        $l->paintMasks($masks, $colour, new Region(1, 1, TW - 2, TH - 2));
        expect(tvfurthest($t->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(ThorVGCanvas::TOLERANCE);
    }
})->with('thorvg formats');

it('paints gradients within its tolerance of lustrous-php', function (FormatSpec $spec, string $kind, GradientSpread $spread) {
    $ramp = '';
    for ($i = 0; $i < 256; $i++) {
        $ramp .= pack('C4', $i, 255 - $i, (int) (128 + 127 * sin($i / 20)), (int) (60 + $i * 0.7));
    }
    $spans = '';
    for ($y = 0; $y < TH; $y++) {
        $spans .= Spans::pack($y, 1, TW - 2, mt_rand(1, 255));
    }
    [$t, $l] = tvboth($spec, rgba8: random_bytes(TW * TH * 4));
    $inverse = [1 / 30, 0.004, -0.01, 1 / 25, -0.1, -0.2];
    $t->paintGradient($spans, $ramp, $kind, $spread, $inverse);
    $l->paintGradient($spans, $ramp, $kind, $spread, $inverse);

    expect(tvfurthest($t->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(ThorVGCanvas::TOLERANCE);
})->with('thorvg formats')->with(['linear', 'radial'])->with([GradientSpread::PAD, GradientSpread::REPEAT, GradientSpread::REFLECT]);

it('moves another framebuffer within its tolerance of lustrous-php', function (FormatSpec $spec, int $opacity) {
    for ($n = 0; $n < 10; $n++) {
        [$t, $l] = tvboth($spec, 48, 40, random_bytes(48 * 40 * 4));
        $source = random_bytes(9 * 7 * 4);
        [$ts, $ls] = tvboth($spec, 9, 7, $source);
        $t->paintImage($ts, Affine::translation(11, 7), $opacity);
        $l->paintImage($ls, Affine::translation(11, 7), $opacity);

        expect(tvfurthest($t->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(ThorVGCanvas::TOLERANCE);
    }
})->with('thorvg formats')->with(['opaque' => [255], 'translucent' => [170]]);

it('draws a scaled or turned framebuffer only inside its footprint, clip and coverage', function (FormatSpec $spec, Affine $placement, Filter $filter) {
    $W = 48;
    $H = 40;
    $background = random_bytes($W * $H * 4);
    $clip = new Region(6, 4, 30, 26);
    $coverage = '';
    for ($y = 0; $y < $H; $y++) {
        if ($y % 3 !== 0) {
            $coverage .= Spans::pack($y, 2, 40, 255);
        }
    }
    [$t] = tvboth($spec, $W, $H, $background);
    [$ts] = tvboth($spec, 9, 7, random_bytes(9 * 7 * 4));
    $before = $t->toRgba8();
    $t->paintImage($ts, $placement, 255, $filter, $clip, 0xFFFFFFFF, null, $coverage);
    $changed = tvchanged($before, $t->toRgba8());

    // the footprint: the placed source's corners, as a polygon in the target
    $corners = array_map(fn ($p) => $placement->apply($p[0], $p[1]), [[0, 0], [9, 0], [9, 7], [0, 7]]);
    $xs = array_column($corners, 0);
    $ys = array_column($corners, 1);
    expect($changed)->not->toBeEmpty();
    foreach ($changed as $i) {
        [$x, $y] = [$i % $W, intdiv($i, $W)];
        expect($x >= 6 && $x < 36 && $y >= 4 && $y < 30)->toBeTrue("changed ($x, $y) outside the clip")
            ->and($y % 3)->not->toBe(0, "changed ($x, $y) on a row with no coverage")
            ->and($x >= floor(min($xs)) - 1 && $x <= ceil(max($xs)) && $y >= floor(min($ys)) - 1 && $y <= ceil(max($ys)))->toBeTrue("changed ($x, $y) outside the footprint");
    }

    $still = new ThorVGCanvas($spec, $W, $H);
    $still->writeRgba8($background, $W, $H);
    $still->paintImage($ts, $placement, 0, $filter);
    expect($still->toRgba8())->toBe($background);
})->with('thorvg formats')->with([
    'scaled, nearest' => [Affine::translation(4.3, 3.1)->multiply(Affine::scaling(2.7, 2.3)), Filter::NEAREST],
    'scaled, linear' => [Affine::translation(4.3, 3.1)->multiply(Affine::scaling(2.7, 2.3)), Filter::LINEAR],
    'turned, nearest' => [Affine::translation(24, 6)->multiply(Affine::rotation(M_PI / 6))->multiply(Affine::scaling(2, 2)), Filter::NEAREST],
    'turned, linear' => [Affine::translation(24, 6)->multiply(Affine::rotation(M_PI / 6))->multiply(Affine::scaling(2, 2)), Filter::LINEAR],
]);

it('offers its pixels as memory for drawing in place', function (FormatSpec $spec) {
    $canvas = new ThorVGCanvas($spec, 8, 4);
    $memory = $canvas->memory();

    expect($memory)->not->toBeNull()
        ->and($canvas->pointer())->not->toBe(0)
        ->and($memory->width)->toBe(8)
        ->and($memory->height)->toBe(4)
        ->and($canvas->drewInto(new Region(0, 0, 2, 2), null))->toBe($canvas);

    // something else drawing into that memory: the canvas reads what it drew
    $other = tvg_swcanvas_create(TVG_ENGINE_OPTION_NONE);
    $cs = $spec == FormatSpec::rgba8() ? TVG_COLORSPACE_ABGR8888S : TVG_COLORSPACE_ARGB8888S;
    expect(tvg_swcanvas_set_target($other, $memory->address, 8, 8, 4, $cs))->toBe(TVG_RESULT_SUCCESS);
    $rect = tvg_shape_new();
    tvg_shape_append_rect($rect, 2.0, 1.0, 3.0, 2.0, 0.0, 0.0, true);
    tvg_shape_set_fill_color($rect, 0x11, 0x22, 0x33, 0xFF);
    tvg_canvas_add($other, $rect);
    tvg_canvas_update($other);
    tvg_canvas_draw($other, false);
    tvg_canvas_sync($other);

    expect($canvas->getPixel(3, 2))->toBe(0x112233FF)
        ->and($canvas->getPixel(0, 0))->toBe(0);
})->with('thorvg formats');

it('keeps its own pixels when cloned', function () {
    $a = new ThorVGCanvas(FormatSpec::rgba8(), 4, 2);
    $a->fill(0x11223344);
    $b = clone $a;
    $b->fill(0xAABBCCDD);

    expect($a->getPixel(0, 0))->toBe(0x11223344)
        ->and($b->getPixel(0, 0))->toBe(0xAABBCCDD);
});

it('hands out reads that later drawing does not change', function () {
    $canvas = new ThorVGCanvas(FormatSpec::rgba8(), 4, 2);
    $canvas->fill(0x11223344);
    $dump = $canvas->dump();
    $canvas->fill(0xAABBCCDD);

    expect($dump)->toBe(str_repeat("\x11\x22\x33\x44", 8));
});

// Library to library is a pointer where ThorVG has the operation: tvg_picture_load_raw reads a
// source's memory, so paintImage takes it in place. ThorVG has no copy (no SRC blend, no byte
// copy into its target), so blitFrom from a C source reads it back.
moduleTicketChecks('thorvg', fn (FormatSpec $f, int $w, int $h) => new ThorVGCanvas($f, $w, $h), FormatSpec::rgba8(), [
    'rgba8' => FormatSpec::rgba8(),
    'bgra8' => FormatSpec::bgra8(),
], ['paintImage']);

it('fills an opaque rect where the pixels are: the frame is not copied to change part of it', function (): void {
    $canvas = new ThorVGCanvas(FormatSpec::rgba8(), 64, 48);
    $canvas->fill(0x102030FF);
    $before = $canvas->pointer();

    $canvas->setSegment(8, 6, 20, 10, 0xFF8000FF);

    expect($canvas->pointer())->toBe($before)
        ->and($canvas->getPixel(8, 6))->toBe(0xFF8000FF)
        ->and($canvas->getPixel(27, 15))->toBe(0xFF8000FF)
        ->and($canvas->getPixel(28, 15))->toBe(0x102030FF)
        ->and($canvas->getPixel(7, 6))->toBe(0x102030FF);
});
