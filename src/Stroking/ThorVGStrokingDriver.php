<?php

namespace Jovian\Rendering\Thor\Stroking;

use Surface\Contracts\Rendering\Rasterization\StrokeGeometry;
use Surface\Rendering\Stroking\StrokingDriver;

/** 'thorvg': ThorVG's stroker. No dashes: ThorVG's dasher is internal to its CPU engine. */
class ThorVGStrokingDriver extends StrokingDriver
{
    private ThorVGStroker $stroker;

    public function __construct()
    {
        $this->stroker = new ThorVGStroker();
    }

    public function driver(): string
    {
        return 'thorvg';
    }

    public function stroker(): StrokeGeometry
    {
        return $this->stroker;
    }
}
