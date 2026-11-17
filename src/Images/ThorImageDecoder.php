<?php

namespace Jovian\Rendering\Thor\Images;

use Surface\Contracts\Images\ImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Contracts\Images\ImageFormat;
use Surface\Contracts\Images\ImageInfo;
use Surface\Contracts\Images\ImageSource;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;

/**
 * ThorVG's address decoder. A picture is loaded and drawn at its natural size onto a software
 * canvas aimed at the frame. The straight colorspaces are used, so an opaque pixel matches the
 * file. A partial pixel can be 1 off: ThorVG premultiplies, then the straight target divides it
 * back out, and that round trip is not exact (120 × 126 ÷ 255 is 59, and 59 × 255 ÷ 126 is 119).
 * The same file draws the same bytes every run, at the picture's size, with no blending into the
 * pixels already in the frame.
 *
 * WebP is a loader ThorVG has and this decoder does not claim. A flat 0x102030 JPEG-style colour
 * stored as WebP at quality 100 came back 0x301522. SVG has no fixed pixel size.
 */
final class ThorImageDecoder implements ImageDecoder
{
    public function driver(): string
    {
        return 'thorvg';
    }

    public function formats(): array
    {
        return [ImageFormat::PNG, ImageFormat::JPEG];
    }

    public function writes(FormatSpec $format): bool
    {
        return $format->pixel_format === PixelFormat::ROW_MAJOR
            && $format->bit_depth === BitDepth::B32
            && $format->scan_direction === ScanDirection::TOP_TO_BOTTOM
            && ($format->channel_order === ChannelOrder::RGBA || $format->channel_order === ChannelOrder::BGRA)
            && is_null($format->palette);
    }

    public function info(ImageSource $source): ImageInfo
    {
        $bytes = $source->bytes();
        $format = ImageFormat::sniff($bytes) ?? throw ImageException::unknownFormat();

        return match ($format) {
            ImageFormat::PNG => $this->pngInfo($bytes),
            ImageFormat::JPEG => $this->jpegInfo($bytes),
            ImageFormat::TIFF => throw ImageException::corrupt($format, 'ThorVG does not read TIFF.'),
        };
    }

    public function decodeInto(ImageSource $source, PixelMemory $target, int $frame = 0): Region
    {
        $bytes = $source->bytes();
        $format = ImageFormat::sniff($bytes) ?? throw ImageException::unknownFormat();
        if (! $this->writes($target->format)) {
            throw ImageException::noDecoder($format, $target->format);
        }
        $facts = $this->info($source);
        if ($frame < 0 || $frame >= $facts->frames) {
            throw ImageException::frameOutOfRange($format, $frame, $facts->frames);
        }
        if ($facts->width !== $target->width || $facts->height !== $target->height) {
            throw ImageException::corrupt($format, "it is {$facts->width}x{$facts->height}, and the frame is {$target->width}x{$target->height}.");
        }
        $row = $target->width * 4;
        if ($target->stride < $row || $target->stride % 4 !== 0) {
            throw ImageException::corrupt($format, "a row is {$row} bytes, and the stride is {$target->stride}.");
        }
        if ($format === ImageFormat::PNG && ! $this->pngHasIdat($bytes)) {
            throw ImageException::corrupt($format, 'the data stops before the image does.');
        }
        extension_loaded('thorvg') || throw new ImageException('ext-thorvg is not loaded in this PHP.');
        $this->call(tvg_engine_init(0), $format, 'tvg_engine_init');

        $picture = tvg_picture_new() ?? throw ImageException::corrupt($format, 'ThorVG could not make a picture.');
        $mime = $format === ImageFormat::JPEG ? 'jpg' : 'png';
        $this->call(tvg_picture_load_data($picture, $bytes, strlen($bytes), $mime, null, true), $format, 'tvg_picture_load_data');
        $width = 0.0;
        $height = 0.0;
        $this->call(tvg_picture_get_size($picture, $width, $height), $format, 'tvg_picture_get_size');
        if ((int) $width !== $facts->width || (int) $height !== $facts->height) {
            throw ImageException::corrupt($format, 'the picture is not the size the header names.');
        }
        $space = $target->format->channel_order === ChannelOrder::RGBA ? TVG_COLORSPACE_ABGR8888S : TVG_COLORSPACE_ARGB8888S;
        $canvas = tvg_swcanvas_create(TVG_ENGINE_OPTION_NONE) ?? throw ImageException::corrupt($format, 'ThorVG has no software canvas in this build.');
        $this->call(tvg_swcanvas_set_target($canvas, $target->address, intdiv($target->stride, 4), $target->width, $target->height, $space), $format, 'tvg_swcanvas_set_target');
        $this->call(tvg_canvas_add($canvas, $picture), $format, 'tvg_canvas_add');
        $this->call(tvg_canvas_update($canvas), $format, 'tvg_canvas_update');
        $this->call(tvg_canvas_draw($canvas, true), $format, 'tvg_canvas_draw');
        $this->call(tvg_canvas_sync($canvas), $format, 'tvg_canvas_sync');
        tvg_canvas_destroy($canvas);

        return new Region(0, 0, $target->width, $target->height);
    }

    private function call(int $result, ImageFormat $format, string $call): void
    {
        if ($result !== TVG_RESULT_SUCCESS) {
            throw ImageException::corrupt($format, "{$call} returned {$result}.");
        }
    }

    /** ThorVG reports success and draws a clear frame when a PNG has no IDAT. That is a refusal here, before the draw. */
    private function pngHasIdat(string $bytes): bool
    {
        $at = 8;
        $length = strlen($bytes);
        while ($at + 8 <= $length) {
            $size = unpack('N', $bytes, $at)[1];
            $type = substr($bytes, $at + 4, 4);
            if ($type === 'IDAT') {
                return true;
            }
            if ($type === 'IEND' || $at + 12 + $size > $length) {
                return false;
            }
            $at += 12 + $size;
        }

        return false;
    }

    private function pngInfo(string $bytes): ImageInfo
    {
        if (strlen($bytes) < 29 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw ImageException::corrupt(ImageFormat::PNG, 'the data stops before the image does.');
        }
        $width = unpack('N', $bytes, 16)[1];
        $height = unpack('N', $bytes, 20)[1];
        $colour = ord($bytes[25]);
        $alpha = $colour === 4 || $colour === 6;
        $at = 8;
        while (! $alpha && $at + 12 <= strlen($bytes)) {
            $length = unpack('N', $bytes, $at)[1];
            $type = substr($bytes, $at + 4, 4);
            if ($type === 'tRNS') {
                $alpha = true;
                break;
            }
            if ($type === 'IDAT' || $type === 'IEND' || $at + 12 + $length > strlen($bytes)) {
                break;
            }
            $at += 12 + $length;
        }

        return new ImageInfo(ImageFormat::PNG, $width, $height, $alpha, 1);
    }

    private function jpegInfo(string $bytes): ImageInfo
    {
        $length = strlen($bytes);
        if ($length < 4 || ! str_starts_with($bytes, "\xFF\xD8")) {
            throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
        }
        $i = 2;
        while ($i + 1 < $length) {
            if (ord($bytes[$i]) !== 0xFF) {
                throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
            }
            while ($i < $length && ord($bytes[$i]) === 0xFF) {
                $i++;
            }
            if ($i >= $length) {
                break;
            }
            $marker = ord($bytes[$i]);
            $i++;
            if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if ($i + 1 >= $length) {
                break;
            }
            $segment = unpack('n', $bytes, $i)[1];
            if ($marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                if ($segment < 8 || $i + 6 >= $length) {
                    throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
                }

                return new ImageInfo(ImageFormat::JPEG, unpack('n', $bytes, $i + 5)[1], unpack('n', $bytes, $i + 3)[1], false, 1);
            }
            if ($segment < 2) {
                throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
            }
            $i += $segment;
        }

        throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
    }
}
