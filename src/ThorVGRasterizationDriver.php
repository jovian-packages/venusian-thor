<?php

namespace Jovian\Rendering\Thor;

use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\RasterizeException;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use VelvetGE\Rasterization\ScannerRasterizationDriver;

/**
 * 'thorvg': paths filled by ThorVG through ext-thorvg, anti-aliased or, for hard edges, with
 * ThorVG's own aliased option. Nothing is borrowed from another module.
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
        return new ThorVGScanner($clip, $edges);
    }
}
