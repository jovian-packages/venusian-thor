<?php

namespace Jovian\Rendering\Thor\Providers;

use Jovian\Rendering\Thor\Framebuffers\ThorVGFramebufferDriver;
use Jovian\Rendering\Thor\Images\ThorImageDecoderDriver;
use Jovian\Rendering\Thor\ThorVGPaintingDriver;
use Jovian\Rendering\Thor\ThorVGRasterizationDriver;
use Jovian\Rendering\Thor\Stroking\ThorVGStrokingDriver;
use ReflectionException;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianThorServiceProvider extends ServiceProvider
{
    /**
     * Registers 'thorvg' on Velvet's framebuffers, rasterizers and painters.
     *
     * @throws ReflectionException
     */
    public function boot(): void
    {
        $this->registerVelvetDrivers();
    }

    protected function registerVelvetDrivers(): void
    {
        $this->registerVelvetFramebufferDriver();
        $this->registerVelvetRasterizationDriver();
        $this->registerVelvetPaintbrushDriver();
        $this->registerStrokingDriver();
        $this->registerImageDecoder();
    }

    /** 'thorvg' on Velvet's rasterizers. */
    protected function registerVelvetRasterizationDriver(): void
    {
        app('velvet.rasterizers')->extend('thorvg', fn () => new ThorVGRasterizationDriver());
    }

    /** 'thorvg' on Velvet's framebuffers. */
    protected function registerVelvetFramebufferDriver(): void
    {
        app('velvet.framebuffers')->extend('thorvg', fn () => new ThorVGFramebufferDriver());
    }

    /** 'thorvg' on Velvet's painters. */
    protected function registerVelvetPaintbrushDriver(): void
    {
        app('velvet.painters')->extend('thorvg', fn () => new ThorVGPaintingDriver());
    }

    /**
     * 'thorvg' on Topside's strokers: stroking is Rendering's, for every engine, not Velvet's.
     *
     * @throws ReflectionException
     */
    /** 'thorvg' on Images' decoders. PNG and JPEG, drawn straight into the frame at its own size. */
    protected function registerImageDecoder(): void
    {
        if (! extension_loaded('thorvg')) {
            return;
        }
        app('images.decoders')->extend('thorvg', fn () => new ThorImageDecoderDriver());
    }

    protected function registerStrokingDriver(): void
    {
        app('strokers')->extend('thorvg', fn () => new ThorVGStrokingDriver());
    }
}
