<?php

namespace Jovian\Rendering\Thor;

use Surface\Contracts\Rendering\Painting\Brush;
use Surface\Contracts\Rendering\Rasterization\RasterizeDriver;
use Surface\Contracts\Rendering\Recording\RecordDriver;
use Surface\Contracts\Rendering\RenderingException;
use Surface\VelvetGE\Drivers\Painting\BrushPaintingDriver;

/**
 * 'thorvg' painting: VelvetGE's painter with ThorVGBrush. Without ext-thorvg the constructor
 * throws, so 'auto' passes over it.
 */
class ThorVGPaintingDriver extends BrushPaintingDriver
{
    /** As BrushPaintingDriver takes them: what VelvetGE's painter rasterizes the rest with, direct fills, and what frames are recorded with. */
    public function __construct(?RasterizeDriver $rasterize = null, bool $directFill = true, ?RecordDriver $record = null)
    {
        function_exists('tvg_swcanvas_get_alpha') || throw new RenderingException('ext-thorvg with tvg_swcanvas_get_alpha() is not loaded.');
        parent::__construct($rasterize, $directFill, $record);
    }

    public function driver(): string
    {
        return 'thorvg';
    }

    public function brush(): Brush
    {
        return new ThorVGBrush();
    }
}
