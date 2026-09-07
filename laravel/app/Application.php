<?php

namespace Digitalogic\Laravel;

use Illuminate\Foundation\Application as LaravelApplication;
use RuntimeException;

final class Application extends LaravelApplication
{
    private static ?self $sharedApplication = null;

    private static bool $integrationBooting = false;

    private string $integrationRuntimePath;

    public static function shared(): ?self
    {
        return self::$sharedApplication;
    }

    /** Publish the container only after providers have booted successfully. */
    public static function bootShared(): self
    {
        if (self::$sharedApplication !== null) {
            return self::$sharedApplication;
        }
        if (self::$integrationBooting) {
            throw new RuntimeException('Recursive Digitalogic Laravel bootstrap.');
        }

        self::$integrationBooting = true;
        $previousContainer = \Illuminate\Container\Container::getInstance();
        $previousFacadeApplication = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        try {
            $app = require dirname(__DIR__) . '/bootstrap/configure.php';
            $app->bootstrapForIntegration();
            self::$sharedApplication = $app;
            return $app;
        } catch (\Throwable $error) {
            \Illuminate\Container\Container::setInstance($previousContainer);
            \Illuminate\Support\Facades\Facade::setFacadeApplication($previousFacadeApplication);
            throw $error;
        } finally {
            self::$integrationBooting = false;
        }
    }

    public function __construct($basePath = null)
    {
        parent::__construct($basePath);

        $this->namespace = __NAMESPACE__ . '\\';
        $this->integrationRuntimePath = $this->resolveRuntimePath();
        $this->prepareRuntimePath();
        $this->useStoragePath($this->integrationRuntimePath . DIRECTORY_SEPARATOR . 'storage');
        // Package providers are declared by this application. A relocated release
        // has no root composer.json and must not infer another vendor/application.
        $manifest = new \Illuminate\Foundation\PackageManifest(
            new \Illuminate\Filesystem\Filesystem(),
            dirname($this->basePath()),
            $this->getCachedPackagesPath()
        );
        $manifest->manifest = [];
        $this->instance(\Illuminate\Foundation\PackageManifest::class, $manifest);
    }

    /** Boot Laravel services without replacing WordPress's global PHP error handler. */
    public function bootstrapForIntegration(): void
    {
        if ($this->hasBeenBootstrapped()) {
            return;
        }

        $this->bootstrapWith([
            \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
            \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
            \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
            \Illuminate\Foundation\Bootstrap\BootProviders::class,
        ]);
    }

    public function getCachedServicesPath(): string
    {
        return $this->cachePath('services.php');
    }

    public function getCachedPackagesPath(): string
    {
        return $this->cachePath('packages.php');
    }

    public function getCachedConfigPath(): string
    {
        return $this->cachePath('config.php');
    }

    public function getCachedRoutesPath(): string
    {
        return $this->cachePath('routes-v7.php');
    }

    public function getCachedEventsPath(): string
    {
        return $this->cachePath('events.php');
    }

    public function integrationRuntimePath(string $path = ''): string
    {
        return $path === ''
            ? $this->integrationRuntimePath
            : $this->integrationRuntimePath . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    private function cachePath(string $file): string
    {
        return $this->integrationRuntimePath('cache' . DIRECTORY_SEPARATOR . $file);
    }

    private function resolveRuntimePath(): string
    {
        if (defined('DIGITALOGIC_LARAVEL_RUNTIME_PATH') && DIGITALOGIC_LARAVEL_RUNTIME_PATH !== '') {
            return rtrim((string) DIGITALOGIC_LARAVEL_RUNTIME_PATH, '/\\');
        }

        return rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR . 'digitalogic-laravel-'
            . substr(sha1((string) $this->basePath()), 0, 12);
    }

    private function prepareRuntimePath(): void
    {
        $directories = [
            $this->integrationRuntimePath('cache'),
            $this->integrationRuntimePath('storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'data'),
            $this->integrationRuntimePath('storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'sessions'),
            $this->integrationRuntimePath('storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'views'),
            $this->integrationRuntimePath('storage' . DIRECTORY_SEPARATOR . 'logs'),
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create the isolated Laravel runtime directory.');
            }
        }
    }
}
