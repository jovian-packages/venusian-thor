<?php

namespace Jovian\Rendering\Thor\Images;

use Surface\Contracts\Images\ImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Images\ImageDecoderDriver;

/** Registers ThorVG's address decoder where ext-thorvg is loaded. */
final class ThorImageDecoderDriver extends ImageDecoderDriver
{
    /**
     * @throws ImageException When ext-thorvg is not loaded. Images skips a decoder that throws this.
     */
    public function __construct()
    {
        extension_loaded('thorvg') || throw new ImageException('ext-thorvg is not loaded in this PHP.');
    }

    public function driver(): string
    {
        return 'thorvg';
    }

    public function decoder(): ImageDecoder
    {
        return new ThorImageDecoder();
    }
}
