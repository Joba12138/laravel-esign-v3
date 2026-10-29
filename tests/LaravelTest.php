<?php

namespace Joba\Esign\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use InvalidArgumentException;
use Joba\Esign\Client;
use Joba\Esign\Laravel\Facades\Esign;
use Joba\Esign\Laravel\Middleware\VerifyWebhookSignature;
use Joba\Esign\Laravel\ServiceProvider;
use PHPUnit\Framework\TestCase;

class LaravelTest extends TestCase
{
    private array $originalPublishes;
    private array $originalPublishGroups;
    private $originalFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPublishes = BaseServiceProvider::$publishes;
        $this->originalPublishGroups = BaseServiceProvider::$publishGroups;
        $this->originalFacadeApplication = Facade::getFacadeApplication();
        BaseServiceProvider::$publishes = [];
        BaseServiceProvider::$publishGroups = [];
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        BaseServiceProvider::$publishes = $this->originalPublishes;
        BaseServiceProvider::$publishGroups = $this->originalPublishGroups;
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->originalFacadeApplication);
        parent::tearDown();
    }

    public function testProviderAndFacadeResolveTheSameSharedClient(): void
    {
        $app = $this->application($this->configuration());
        Facade::setFacadeApplication($app);
        (new ServiceProvider($app))->register();

        $client = $app->make(Client::class);

        $this->assertSame($client, $app->make(Client::class));
        $this->assertSame($client, $app->make('esign'));
        $this->assertSame($client, Esign::getFacadeRoot());
        $this->assertSame($client->file(), Esign::file());
        $this->assertSame($client->signFlow(), Esign::signFlow());
    }

    public function testProviderMergesDefaultsAndPreservesApplicationConfiguration(): void
    {
        $app = $this->application(array_merge($this->configuration(), [
            'timeout' => 4.5,
            'sandbox' => false,
            'callback_tolerance' => 90,
        ]));

        (new ServiceProvider($app))->register();

        $config = $app->make('config');
        $this->assertSame('test-app', $config->get('esign.app_id'));
        $this->assertSame('test-secret', $config->get('esign.app_secret'));
        $this->assertSame(4.5, $config->get('esign.timeout'));
        $this->assertFalse($config->get('esign.sandbox'));
        $this->assertSame(90, $config->get('esign.callback_tolerance'));
        $this->assertArrayHasKey('connect_timeout', $config->get('esign'));
        $this->assertArrayHasKey('upload_timeout', $config->get('esign'));
        $this->assertArrayHasKey('base_url', $config->get('esign'));
    }

    public function testRegistrationIsLazyAndDoesNotRequireCredentials(): void
    {
        $app = $this->application(['app_id' => '', 'app_secret' => '']);

        (new ServiceProvider($app))->register();

        $this->assertTrue($app->bound(Client::class));
        $this->assertTrue($app->bound('esign'));
        $this->assertFalse($app->resolved(Client::class));
        $this->assertFalse($app->resolved('esign'));
        $this->expectException(InvalidArgumentException::class);

        $app->make(Client::class);
    }

    public function testConsoleBootRegistersThePackageConfigForPublishing(): void
    {
        $app = $this->application([], true);
        $provider = new ServiceProvider($app);
        $provider->register();
        $provider->boot();

        $paths = BaseServiceProvider::pathsToPublish(ServiceProvider::class, 'esign-config');
        $this->assertCount(1, $paths);
        $source = array_key_first($paths);
        $this->assertSame(realpath(dirname(__DIR__) . '/config/esign.php'), realpath($source));
        $this->assertSame('/application/config/esign.php', $paths[$source]);
        $this->assertFalse($app->resolved(Client::class));
    }

    public function testWebBootDoesNotRegisterConsolePublishPaths(): void
    {
        $app = $this->application([], false);
        $provider = new ServiceProvider($app);
        $provider->register();
        $provider->boot();

        $this->assertSame([], BaseServiceProvider::pathsToPublish(ServiceProvider::class, 'esign-config'));
        $this->assertSame([], BaseServiceProvider::pathsToPublish(ServiceProvider::class, 'esign-middleware'));
        $this->assertFalse($app->resolved(Client::class));
    }

    public function testConsolePublishingOnlyContainsConfiguration(): void
    {
        $app = $this->application([], true);
        $provider = new ServiceProvider($app);
        $provider->register();
        $provider->boot();

        $paths = BaseServiceProvider::pathsToPublish(ServiceProvider::class);
        $this->assertCount(1, $paths);
        $source = array_key_first($paths);
        $this->assertSame(realpath(dirname(__DIR__) . '/config/esign.php'), realpath($source));
        $this->assertSame('/application/config/esign.php', $paths[$source]);
        $this->assertSame([], BaseServiceProvider::pathsToPublish(ServiceProvider::class, 'esign-middleware'));
        $this->assertFalse($app->resolved(Client::class));
    }

    /** @dataProvider routerBootContexts */
    public function testMiddlewareAliasWorksForResolvedAndLazyRoutersWithoutCreatingAClient(bool $console, bool $resolved): void
    {
        $app = $this->application(['app_id' => '', 'app_secret' => ''], $console);
        $app->singleton('router', function () {
            return new LaravelTestRouter();
        });
        if ($resolved) {
            $app->make('router');
        }
        $provider = new ServiceProvider($app);
        $provider->register();
        $provider->boot();

        $this->assertSame($resolved, $app->resolved('router'));
        $this->assertSame(VerifyWebhookSignature::class, $app->make('router')->middleware['esign.signature']);
        $this->assertFalse($app->resolved(Client::class));
    }

    public function routerBootContexts(): array
    {
        return [
            'web with lazy router' => [false, false],
            'web with existing router' => [false, true],
            'console with lazy router' => [true, false],
            'console with existing router' => [true, true],
        ];
    }

    private function application(array $configuration, bool $console = false): LaravelTestApplication
    {
        $app = new LaravelTestApplication($console);
        $app->instance('config', new Repository(['esign' => $configuration]));

        return $app;
    }

    private function configuration(): array
    {
        return ['app_id' => 'test-app', 'app_secret' => 'test-secret', 'sandbox' => true];
    }
}

/** Only the application methods used by this provider; no business application is booted. */
class LaravelTestApplication extends Container
{
    private bool $console;

    public function __construct(bool $console)
    {
        $this->console = $console;
    }

    public function runningInConsole(): bool
    {
        return $this->console;
    }

    public function configPath(string $path = ''): string
    {
        return '/application/config' . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    public function path(string $path = ''): string
    {
        return '/application/app' . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }
}

/** Captures the router contract without requiring a full framework installation. */
class LaravelTestRouter
{
    public array $middleware = [];

    public function aliasMiddleware(string $name, string $class): void
    {
        $this->middleware[$name] = $class;
    }
}
