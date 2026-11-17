<?php

declare(strict_types=1);

require_once __DIR__.'/../../src/Images/ThorImageDecoder.php';

use Jovian\Rendering\Thor\Images\ThorImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Contracts\Images\ImageFormat;
use Surface\Contracts\Images\ImageSource;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;

/*
 * ThorVG draws the picture into a straight colorspace. gd's alpha is 7 bits, widened as
 * 255 − ((a << 1) + (a >> 6)): 0 → 255, 1 → 253, 64 → 126, 127 → 0. ThorVG premultiplies and the
 * straight target divides it back out. Pixel 1: 60 × 253 ÷ 255 = 59, and 59 × 255 ÷ 253 = 59,
 * so red 60 comes back 59 (0x3B) and blue 7 comes back 6. Pixel 2: 120 × 126 ÷ 255 = 59, and
 * 59 × 255 ÷ 126 = 119, so red 120 comes back 119 (0x77) and blue 7 comes back 6. Opaque pixel 0
 * is the file's 0x000007FF. The frame is filled with 0xA5 first, and those bytes are replaced.
 */

function thorPng(): string
{
    $image = imagecreatetruecolor(4, 1);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $alphas = [0, 1, 64, 127];
    for ($x = 0; $x < 4; $x++) {
        imagesetpixel($image, $x, 0, ($alphas[$x] << 24) | ($x * 60 << 16) | 7);
    }
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

function thorMemory(int $width, int $height, int $stride, FormatSpec $format): array
{
    $order = $format->channel_order === ChannelOrder::RGBA ? FB_CHANNELS_RGBA : FB_CHANNELS_BGRA;
    $buffer = new FbBuffer(new FbFormat(FB_LAYOUT_RGBA8888, channelOrder: $order), $width, $height);
    $memory = new PixelMemory($buffer->pointer(), $width, $height, $stride, $format, $buffer);

    return [$buffer, $memory];
}

it('writes a PNG straight, opaque exact, a partial pixel within the premultiply round trip', function () {
    extension_loaded('thorvg') || throw new RuntimeException('ext-thorvg is not loaded in this PHP.');
    $png = thorPng();
    $decoder = new ThorImageDecoder();
    $info = $decoder->info(ImageSource::containing($png));
    [$buffer, $memory] = thorMemory(4, 1, 16, FormatSpec::rgba8());
    $buffer->rect(0, 0, 4, 1, 0xA5);
    $decoder->decodeInto(ImageSource::containing($png), $memory);

    expect($info->format)->toBe(ImageFormat::PNG)
        ->and($info->hasAlpha)->toBeTrue()
        ->and($decoder->formats())->toBe([ImageFormat::PNG, ImageFormat::JPEG])
        ->and($buffer->get(0, 0))->toBe(0x000007FF)
        ->and($buffer->get(1, 0))->toBe(0x3B0006FD)
        ->and($buffer->get(2, 0))->toBe(0x7700067E)
        ->and($buffer->get(3, 0))->toBe(0x00000000);
});

it('writes a flat JPEG as opaque pixels equal to the file, in BGRA', function () {
    extension_loaded('thorvg') || throw new RuntimeException('ext-thorvg is not loaded in this PHP.');
    $image = imagecreatetruecolor(2, 1);
    imagesetpixel($image, 0, 0, 0x102030);
    imagesetpixel($image, 1, 0, 0x405060);
    ob_start();
    imagejpeg($image, null, 100);
    $jpeg = ob_get_clean();
    [$buffer, $memory] = thorMemory(2, 1, 8, FormatSpec::bgra8());
    $buffer->rect(0, 0, 2, 1, 0xA5);
    $info = (new ThorImageDecoder())->info(ImageSource::containing($jpeg));
    (new ThorImageDecoder())->decodeInto(ImageSource::containing($jpeg), $memory);

    expect($info->format)->toBe(ImageFormat::JPEG)
        ->and($info->hasAlpha)->toBeFalse()
        ->and($buffer->get(0, 0))->toBe(0x102030FF)
        ->and($buffer->get(1, 0))->toBe(0x405060FF);
});

it('reads a PNG header that ends after IHDR', function () {
    $info = (new ThorImageDecoder())->info(ImageSource::containing(substr(thorPng(), 0, 33)));

    expect($info->width)->toBe(4)->and($info->height)->toBe(1)->and($info->hasAlpha)->toBeTrue();
});

it('leaves the stride padding and refuses a short row before writing', function () {
    extension_loaded('thorvg') || throw new RuntimeException('ext-thorvg is not loaded in this PHP.');
    $image = imagecreatetruecolor(2, 2);
    imagesetpixel($image, 0, 0, 7);
    imagesetpixel($image, 1, 0, 0x112233);
    imagesetpixel($image, 0, 1, 0x102030);
    imagesetpixel($image, 1, 1, 0x405060);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    $owner = new FbBuffer(new FbFormat(FB_LAYOUT_INDEX8), 16, 2);
    $owner->rect(0, 0, 16, 2, 0xA5);
    $memory = new PixelMemory($owner->pointer(), 2, 2, 16, FormatSpec::rgba8(), $owner);
    (new ThorImageDecoder())->decodeInto(ImageSource::containing($png), $memory);
    $view = FbBuffer::over(new FbFormat(FB_LAYOUT_RGBA8888, channelOrder: FB_CHANNELS_RGBA), 2, 2, $owner->pointer(), $owner, 16);

    expect($view->get(0, 1))->toBe(0x102030FF);
    for ($byte = 8; $byte < 16; $byte++) {
        expect($owner->get($byte, 0))->toBe(0xA5);
    }

    [$short, $shortMemory] = thorMemory(4, 1, 8, FormatSpec::rgba8());
    $short->rect(0, 0, 4, 1, 0xA5);
    expect(fn () => (new ThorImageDecoder())->decodeInto(ImageSource::containing(thorPng()), $shortMemory))
        ->toThrow(ImageException::class, 'a row is 16 bytes, and the stride is 8.');
    expect($short->get(0, 0))->toBe(0xA5);
});

it('refuses a target of the wrong size before writing it', function () {
    extension_loaded('thorvg') || throw new RuntimeException('ext-thorvg is not loaded in this PHP.');
    [$buffer, $memory] = thorMemory(2, 1, 8, FormatSpec::rgba8());
    $buffer->rect(0, 0, 2, 1, 0xA5);

    expect(fn () => (new ThorImageDecoder())->decodeInto(ImageSource::containing(thorPng()), $memory))
        ->toThrow(ImageException::class, 'it is 4x1, and the frame is 2x1.');
    expect($buffer->get(0, 0))->toBe(0xA5);
});

it('refuses a PNG with no IDAT before ThorVG draws a clear frame over the target', function () {
    extension_loaded('thorvg') || throw new RuntimeException('ext-thorvg is not loaded in this PHP.');
    [$buffer, $memory] = thorMemory(4, 1, 16, FormatSpec::rgba8());
    $buffer->rect(0, 0, 4, 1, 0xA5);

    expect(fn () => (new ThorImageDecoder())->decodeInto(ImageSource::containing(substr(thorPng(), 0, 40)), $memory))
        ->toThrow(ImageException::class, 'the data stops before the image does.');
    expect($buffer->get(0, 0))->toBe(0xA5);
});
