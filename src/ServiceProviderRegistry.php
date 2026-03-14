<?php

declare(strict_types=1);

namespace Kode\DI;

use Kode\DI\Contract\ContainerInterface;
use Kode\DI\Exception\ContainerException;

final class ServiceProviderRegistry
{
    private ContainerInterface $container;

    private array $providers = [];

    private array $loaded = [];

    private array $deferredProviders = [];

    private array $providesMap = [];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function register(string|ServiceProvider $provider): void
    {
        if (is_string($provider)) {
            $provider = new $provider($this->container);
        }

        $class = get_class($provider);

        if (isset($this->loaded[$class])) {
            return;
        }

        if ($provider->isDeferred()) {
            $this->registerDeferred($provider);
            return;
        }

        $this->loadProvider($provider);
    }

    public function boot(): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot();
        }
    }

    public function resolveDeferred(string $id): bool
    {
        if (!isset($this->providesMap[$id])) {
            return false;
        }

        $providerClass = $this->providesMap[$id];

        if (isset($this->loaded[$providerClass])) {
            return true;
        }

        if (!isset($this->deferredProviders[$providerClass])) {
            return false;
        }

        $provider = $this->deferredProviders[$providerClass];
        $this->loadProvider($provider);

        unset($this->deferredProviders[$providerClass]);

        return true;
    }

    public function hasDeferred(string $id): bool
    {
        return isset($this->providesMap[$id]);
    }

    public function getProviders(): array
    {
        return $this->providers;
    }

    public function getLoaded(): array
    {
        return array_keys($this->loaded);
    }

    private function registerDeferred(ServiceProvider $provider): void
    {
        $class = get_class($provider);

        $this->deferredProviders[$class] = $provider;

        foreach ($provider->provides() as $id) {
            $this->providesMap[$id] = $class;
        }
    }

    private function loadProvider(ServiceProvider $provider): void
    {
        $class = get_class($provider);

        $provider->register();

        $this->providers[] = $provider;
        $this->loaded[$class] = true;
    }
}
