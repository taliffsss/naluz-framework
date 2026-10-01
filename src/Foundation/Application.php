<?php

declare(strict_types=1);

namespace Naluz\Foundation;

use Naluz\Config\Repository;
use Naluz\Container\Container;
use Naluz\Database\Orm\Model;
use Naluz\Http\Request;
use Naluz\Routing\Pipeline;
use Naluz\Routing\Router;
use Naluz\Support\Env;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Application extends Container
{
    public const VERSION = '1.0.0';

    private static ?self $instance = null;
    private bool $booted = false;
    /** @var list<ServiceProvider> */
    private array $providers = [];

    /** @param array<string,mixed> $configOverrides dotted-key overrides, handy for tests */
    public function __construct(private readonly string $basePath, private readonly array $configOverrides = [])
    {
        self::$instance = $this;
        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
        $this->instance(ContainerInterface::class, $this);
    }

    public static function getInstance(): self
    {
        return self::$instance ?? throw new \LogicException('The NaluzPHP application has not been created.');
    }

    public function basePath(string $path = ''): string
    {
        return rtrim($this->basePath, '/\\') . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }
        $this->booted = true;

        Env::load($this->basePath('.env'));
        $config = Repository::fromDirectory($this->basePath('config'));
        foreach ($this->configOverrides as $key => $value) {
            $config->set($key, $value);
        }
        $this->instance(Repository::class, $config);
        date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));
        mb_internal_encoding('UTF-8');

        $classes = [
            CoreServiceProvider::class,
            \Naluz\Queue\QueueServiceProvider::class,
            \Naluz\Mail\MailServiceProvider::class,
            \Naluz\Storage\StorageServiceProvider::class,
            \Naluz\Schedule\ScheduleServiceProvider::class,
            \Naluz\Database\ModelCacheServiceProvider::class,
            \Naluz\NoSql\NoSqlServiceProvider::class,
            \Naluz\GraphQL\GraphQLServiceProvider::class,
            ...(new PackageManifest($this->basePath))->providers((array) $config->get('app.dont_discover', [])),
            ...(array) $config->get('app.providers', []),
        ];
        foreach ($classes as $class) {
            $provider = new $class($this);
            $provider->register();
            $this->providers[] = $provider;
        }
        Model::setContainer($this);
        Model::preventLazyLoading((bool) ($config->get('app.prevent_lazy_loading')
            ?? ((bool) $config->get('app.debug', false) || in_array($config->get('app.env'), ['local', 'testing'], true))));
        foreach ($this->providers as $provider) {
            $provider->boot();
        }
        $this->loadRoutes($config);
        return $this;
    }

    public function isDebug(): bool
    {
        return (bool) $this->make(Repository::class)->get('app.debug', false);
    }

    public function routeCachePath(): string
    {
        return (string) ($this->make(Repository::class)->get('app.routes_cache') ?: $this->basePath('storage/cache/routes.php'));
    }

    private function loadRoutes(Repository $config): void
    {
        $router = $this->make(Router::class);
        foreach ((array) $config->get('app.middleware_aliases', []) as $alias => $class) {
            $router->aliasMiddleware($alias, $class);
        }
        foreach ((array) $config->get('app.middleware_groups', []) as $name => $stack) {
            $router->middlewareGroup($name, $stack);
        }
        // A route cache is ignored while debugging so edits to routes/*.php show up immediately.
        if (!$config->get('app.debug') && is_file($cache = $this->routeCachePath())) {
            $table = require $cache;
            if (is_array($table)) {
                $router->loadCached($table);
                return;
            }
        }
        $this->registerRouteFiles($router);
    }

    /** Include routes/web.php and routes/api.php into the given router. */
    public function registerRouteFiles(Router $router): void
    {
        if (is_file($file = $this->basePath('routes/web.php'))) {
            $router->group(['middleware' => ['web']], static function (Router $router) use ($file): void {
                require $file;
            });
        }
        if (is_file($file = $this->basePath('routes/api.php'))) {
            $router->group(['prefix' => 'api', 'middleware' => ['api'], 'name' => 'api.'], static function (Router $router) use ($file): void {
                require $file;
            });
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->boot();
        $this->instance(ServerRequestInterface::class, $request);
        $config = $this->make(Repository::class);
        $handlers = $this->make(ExceptionHandler::class);

        $router = $this->make(Router::class);
        $core = new class ($router, $handlers, $this) implements RequestHandlerInterface {
            public function __construct(private Router $router, private ExceptionHandler $handler, private Container $c)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                try {
                    return $this->router->handle($request);
                } catch (\Throwable $e) {
                    return $this->handler->render($e, $request);
                }
            }
        };

        $pipeline = (new Pipeline($this, (array) $config->get('app.middleware_aliases', [])))
            ->through((array) $config->get('app.middleware', []))
            ->then($core);

        try {
            return $pipeline->handle($request);
        } catch (\Throwable $e) {
            return $handlers->render($e, $request);
        }
    }

    /** Log PHP warnings, uncaught exceptions and fatal errors (see ErrorHandler). Called by run() and the CLI. */
    public function registerErrorHandler(): ErrorHandler
    {
        $this->boot();
        $handler = new ErrorHandler($this->make(\Psr\Log\LoggerInterface::class), $this->isDebug());
        $handler->register();
        return $handler;
    }

    /** Capture the current HTTP request, handle it and send the response. */
    public function run(): void
    {
        $this->registerErrorHandler();
        $request = Request::capture();
        (new Emitter())->emit($this->handle($request), $request->getMethod() === 'HEAD');
    }
}
