<?php

namespace Jovian\Rendering\Thor;

use RasterScanner;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\RasterizeException;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use Surface\VelvetGE\Drivers\Rasterization\ScannerRasterizationDriver;
use Surface\VelvetGE\Rasterize\CLandScanner;
use Surface\VelvetGE\Rasterize\PHPLandScanner;

/**
 * 'thorvg': anti-aliased paths filled by ThorVG through ext-thorvg. Hard edges are VelvetGE's
 * (ext-rasterize's scanner when loaded, else the PHP one), whose pixel rules are exact; ThorVG's
 * aliased mode is experimental and has its own.
 */
class ThorVGRasterizationDriver extends ScannerRasterizationDriver
{
    public function __construct()
    {
        if (! function_exists('tvg_swcanvas_get_alpha')) {
            throw RasterizeException::extensionMissing('thorvg', 'ext-thorvg');
        }
    }

    public function driver(): string
    {
        return 'thorvg';
    }

    protected function scanner(Region $clip, Edges $edges): Scanner
    {
        if ($edges === Edges::HARD) {
            return class_exists(RasterScanner::class) ? new CLandScanner($clip, $edges) : new PHPLandScanner($clip, $edges);
        }

        return new ThorVGScanner($clip, $edges);
    }
}
