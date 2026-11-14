<?php

namespace Jovian\Rendering\Thor\Providers;

use Jovian\Rendering\Thor\ThorVGPaintingDriver;
use Jovian\Rendering\Thor\ThorVGRasterizationDriver;
use ReflectionException;
use Surface\Rendering\Painters;
use Surface\Rendering\Rasterization;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianThorServiceProvider extends ServiceProvider
{
    /**
     * Registers 'thorvg' on Topside's rasterization and painting managers.
     *
     * @throws ReflectionException
     */
    public function boot(): void
    {
        Rasterization::manager()->extend('thorvg', fn () => new ThorVGRasterizationDriver());
        Painters::manager()->extend('thorvg', fn () => new ThorVGPaintingDriver());
    }
}
