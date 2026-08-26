<?php

declare(strict_types=1);

namespace Kode\DI\Tests\Unit;

use Kode\DI\Attributes\Autowire;
use Kode\DI\Attributes\Inject;
use Kode\DI\Container;
use PHPUnit\Framework\TestCase;

/**
 * 编译 / 反射缓存测试
 *
 * 验证控制器（与普通服务类）解析走编译元数据缓存：
 * - 构造注入、属性注入（#[Inject] 显式 id / #[Autowire] / 类型派生）均走编译元数据
 * - clearCache() 后仍能从零重建编译定义并正确解析
 * - 单例身份稳定、原型每次重建（均复用编译元数据）
 * - warmup() 预热不报错（含不存在的类）
 * - call() 控制器动作复用 ReflectionMethod 缓存
 */
class CompiledCacheTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testConstructorAndPropertyInjectionViaCompiledCache(): void
    {
        $this->container->bind('compiled.logger', CompiledLogger::class);
        $this->container->bind(CompiledController::class);
        $controller = $this->container->make(CompiledController::class);

        $this->assertSame('data:log', $controller->handle());

        // 单例身份稳定（构造与属性注入均已通过编译元数据完成）
        $again = $this->container->make(CompiledController::class);
        $this->assertSame($controller, $again);
    }

    public function testPropertyInjectionVariants(): void
    {
        $this->container->bind('compiled.explicit', CompiledExplicit::class);

        $target = $this->container->make(CompiledPropTarget::class);

        // #[Inject(id)] 显式 id
        $this->assertInstanceOf(CompiledExplicit::class, $target->explicit);
        // #[Autowire] 按类型派生
        $this->assertInstanceOf(CompiledByTypeA::class, $target->autowired);
        // #[Inject] 无 id 按类型派生
        $this->assertInstanceOf(CompiledByTypeB::class, $target->typed);
    }

    public function testPrivatePropertyInjectionUsesCachedReflection(): void
    {
        $this->container->bind('compiled.logger', CompiledLogger::class);
        $this->container->bind(CompiledPrivateTarget::class);

        $target = $this->container->make(CompiledPrivateTarget::class);
        $this->assertSame('log', $target->tag());

        // 解析两次：缓存的 ReflectionProperty 复用且 setAccessible 幂等
        $target2 = $this->container->make(CompiledPrivateTarget::class);
        $this->assertSame('log', $target2->tag());
        $this->assertSame($target, $target2);
    }

    public function testUnionTypeConstructorParameterResolves(): void
    {
        $this->container->bind(CompiledUnionA::class);

        $target = $this->container->make(CompiledUnionTarget::class);
        $this->assertInstanceOf(CompiledUnionA::class, $target->dep);
    }

    public function testPrototypeRebuildsEachTimeButReusesCompiledCache(): void
    {
        $this->container->prototype(CompiledRepo::class);

        $r1 = $this->container->make(CompiledRepo::class);
        $r2 = $this->container->make(CompiledRepo::class);

        $this->assertNotSame($r1, $r2);
    }

    public function testClearCacheRebuildsCompiledDefinition(): void
    {
        $this->container->bind('compiled.logger', CompiledLogger::class);
        $before = $this->container->make(CompiledController::class);
        $this->assertSame('data:log', $before->handle());

        // 清空静态反射 / 编译 / 方法缓存（不影响已解析实例缓存）
        Container::clearCache();

        // 全新容器从零重建编译定义，仍可正确解析
        $fresh = new Container();
        $fresh->bind('compiled.logger', CompiledLogger::class);
        $after = $fresh->make(CompiledController::class);
        $this->assertSame('data:log', $after->handle());
    }

    public function testWarmupPrecompilesWithoutError(): void
    {
        $this->container->bind('compiled.logger', CompiledLogger::class);

        // 包含不存在的类应为 no-op，不抛异常
        $this->container->warmup([CompiledController::class, 'ThisClassDoesNotExist']);
        $this->container->warmup([]);

        $controller = $this->container->make(CompiledController::class);
        $this->assertSame('data:log', $controller->handle());
    }

    public function testCallControllerActionUsesMethodReflectionCache(): void
    {
        $this->container->bind('compiled.logger', CompiledLogger::class);

        $r1 = $this->container->call([CompiledActionController::class, 'action'], ['name' => 'hi']);
        $r2 = $this->container->call([CompiledActionController::class, 'action'], ['name' => 'yo']);

        $this->assertSame('hi:log', $r1);
        $this->assertSame('yo:log', $r2);
    }

    public function testContainerHelperWarmup(): void
    {
        $helper = new Container();
        \Kode\DI\ContainerHelper::setInstance($helper);
        $helper->bind('compiled.logger', CompiledLogger::class);

        \Kode\DI\ContainerHelper::warmup([CompiledController::class]);

        $controller = $helper->make(CompiledController::class);
        $this->assertSame('data:log', $controller->handle());
    }
}

// 辅助类
// ---------------------------------------------------------------

class CompiledLogger
{
    public string $tag = 'log';
}

class CompiledRepo
{
    public function find(): string
    {
        return 'data';
    }
}

class CompiledController
{
    public function __construct(
        private CompiledRepo $repo
    ) {
    }

    #[Inject('compiled.logger')]
    public CompiledLogger $logger;

    public function handle(): string
    {
        return $this->repo->find() . ':' . $this->logger->tag;
    }
}

class CompiledExplicit
{
}

class CompiledByTypeA
{
}

class CompiledByTypeB
{
}

class CompiledPropTarget
{
    #[Inject('compiled.explicit')]
    public CompiledExplicit $explicit;

    #[Autowire]
    public CompiledByTypeA $autowired;

    #[Inject]
    public CompiledByTypeB $typed;
}

class CompiledPrivateTarget
{
    #[Inject('compiled.logger')]
    private CompiledLogger $logger;

    public function tag(): string
    {
        return $this->logger->tag;
    }
}

class CompiledUnionA
{
}

class CompiledUnionB
{
}

class CompiledUnionTarget
{
    public function __construct(CompiledUnionA|CompiledUnionB $dep)
    {
        $this->dep = $dep;
    }

    public object $dep;
}

class CompiledActionController
{
    public function __construct(
        private CompiledLogger $logger
    ) {
    }

    public function action(string $name): string
    {
        return $name . ':' . $this->logger->tag;
    }
}
