<?php

namespace Jovian\Rendering\Thor\Framebuffers;

use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Rendering\Framebuffers\FramebufferDriver;

/** ThorVG as a Velvet framebuffer module: every make() is one ThorVGCanvas (RGBA8 or BGRA8). */
class ThorVGFramebufferDriver extends FramebufferDriver
{
    /** @throws FramebufferException When ext-thorvg is not loaded. */
    public function __construct()
    {
        if (! extension_loaded('thorvg')) {
            throw FramebufferException::extensionMissing('thorvg', '0.11.0');
        }
    }

    public function driver(): string
    {
        return 'thorvg';
    }

    /** @throws FramebufferException For any format but RGBA8 and BGRA8. */
    public function make(FormatSpec $format, int $width, int $height): ThorVGCanvas
    {
        return new ThorVGCanvas($format, $width, $height);
    }
}
