<?php

declare(strict_types=1);

namespace Kode\DI;

use Kode\DI\Contract\ContainerInterface;
use Kode\DI\Exception\ContainerException;

final class ServiceProviderRegistry
{
    /** @var array<int, ServiceProvider> */
    private array $providers = [];

    /** @var array<string, true> */
    private array $loaded = [];

    /** @var array<string, true> 已完成 boot 的提供者（保证幂等） */
    private array $bootedProviders = [];

    /** @var array<string, ServiceProvider> */
    private array $deferredProviders = [];

    /** @var array<string, string> */
    private array $providesMap = [];

    private bool $booted = false;

    public function __construct(
        private ContainerInterface $container
    ) {
    }

    public function register(string|ServiceProvider $provider): void
    {
        if (is_string($provider)) {
            $provider = new $provider($this->container);
        }

        /** @var ServiceProvider $provider */
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

    /**
     * 启动所有已注册的提供者
     *
     * 幂等：每个提供者的 boot() 至多执行一次，重复调用安全。
     */
    public function boot(): void
    {
        $this->booted = true;

        foreach ($this->providers as $provider) {
            $this->bootProvider($provider);
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

    /**
     * @return array<int, ServiceProvider>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * @return array<int, string>
     */
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

        // 若容器已启动，则延迟加载的提供者立即启动
        if ($this->booted) {
            $this->bootProvider($provider);
        }
    }

    /**
     * 启动单个提供者（幂等）
     */
    private function bootProvider(ServiceProvider $provider): void
    {
        $class = get_class($provider);

        if (isset($this->bootedProviders[$class])) {
            return;
        }

        $this->bootedProviders[$class] = true;
        $provider->boot();
    }
}
