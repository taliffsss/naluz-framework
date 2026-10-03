<?php

declare(strict_types=1);

namespace Naluz\Console\Commands;

use Naluz\Console\Command;
use Naluz\Console\Input;
use Naluz\Console\Output;
use Naluz\Foundation\Application;
use Naluz\Support\Str;

/** Generators: make:controller, make:model, make:middleware, make:migration, make:provider, make:observer, make:subscriber. */
final class MakeCommand extends Command
{
    public function __construct(Application $app, private readonly string $kind = 'controller')
    {
        parent::__construct($app);
    }

    public static function instances(Application $app): array
    {
        return array_map(fn ($k) => new self($app, $k), ['controller', 'model', 'middleware', 'migration', 'factory', 'seeder', 'job', 'provider', 'observer', 'subscriber']);
    }

    public function name(): string
    {
        return 'make:' . $this->kind;
    }

    public function description(): string
    {
        return "Create a new {$this->kind}";
    }

    public function handle(Input $input, Output $output): int
    {
        $name = $input->argument(0);
        // Strict allow-list: blocks `../` traversal and namespace injection into generated code.
        if ($name === null || !preg_match('#^[A-Za-z][A-Za-z0-9_/]*$#', $name)) {
            $output->error("Usage: php naluz {$this->name()} <Name>   (letters, digits, underscores, '/')");
            return 1;
        }

        [$path, $code] = $this->kind === 'migration' ? $this->migration($name) : $this->classFile($name);
        $full = $this->app->basePath($path);
        if (is_file($full)) {
            $output->error("{$path} already exists.");
            return 1;
        }
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }
        file_put_contents($full, $code);
        $output->info("Created {$path}");
        return 0;
    }

    /** @return array{0:string,1:string} */
    private function classFile(string $name): array
    {
        // kind => [base namespace, directory]
        [$root, $dir] = [
            'controller' => ['App\\Http\\Controllers', 'app/Http/Controllers'],
            'model' => ['App\\Models', 'app/Models'],
            'middleware' => ['App\\Http\\Middleware', 'app/Http/Middleware'],
            'job' => ['App\\Jobs', 'app/Jobs'],
            'provider' => ['App\\Providers', 'app/Providers'],
            'observer' => ['App\\Observers', 'app/Observers'],
            'subscriber' => ['App\\Subscribers', 'app/Subscribers'],
            'factory' => ['Database\\Factories', 'database/factories'],
            'seeder' => ['Database\\Seeders', 'database/seeders'],
        ][$this->kind];
        $parts = array_map([Str::class, 'studly'], explode('/', $name));
        $class = array_pop($parts);
        $ns = implode('\\', [$root, ...$parts]);
        $path = $dir . '/' . implode('/', $parts) . ($parts ? '/' : '') . $class . '.php';

        $body = match ($this->kind) {
            'controller' => <<<PHP
final class {$class}
{
    public function index(): array
    {
        return [];
    }
}
PHP,
            'model' => <<<PHP
class {$class} extends \\Naluz\\Database\\Orm\\Model
{
    /** Attributes that may be mass-assigned. Mass assignment is disabled until you list them. */
    protected array \$fillable = [];
}
PHP,
            'factory' => <<<PHP
final class {$class} extends \\Naluz\\Database\\Factory
{
    protected string \$model = \\App\\Models\\Model::class; // TODO: point at your model

    public function definition(): array
    {
        return [
            'name' => \$this->fake()->name(),
        ];
    }
}
PHP,
            'seeder' => <<<PHP
final class {$class} extends \\Naluz\\Database\\Seeder
{
    public function run(): void
    {
        //
    }
}
PHP,
            'job' => <<<PHP
final class {$class} extends \\Naluz\\Queue\\Job
{
    public function __construct(public readonly int \$id = 0)
    {
    }

    public function handle(): void
    {
        //
    }
}
PHP,
            'provider' => <<<PHP
final class {$class} extends \\Naluz\\Foundation\\ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
PHP,
            'subscriber' => <<<PHP
/** Register in config/messaging.php under `subscribers`, e.g. 'orders.placed' => [{$class}::class]. Must be idempotent. */
final class {$class} implements \\Naluz\\Messaging\\Subscriber
{
    public function handle(\\Naluz\\Messaging\\Message \$message): void
    {
        //
    }
}
PHP,
            'observer' => <<<PHP
/** Attach with #[ObservedBy({$class}::class)] on the model, or Model::observe({$class}::class) in a service provider. */
final class {$class}
{
    public function creating(\\Naluz\\Database\\Orm\\Model \$model): void
    {
        //
    }

    public function created(\\Naluz\\Database\\Orm\\Model \$model): void
    {
        //
    }

    public function updating(\\Naluz\\Database\\Orm\\Model \$model): void
    {
        //
    }

    public function updated(\\Naluz\\Database\\Orm\\Model \$model): void
    {
        //
    }

    public function deleting(\\Naluz\\Database\\Orm\\Model \$model): void
    {
        //
    }

    public function deleted(\\Naluz\\Database\\Orm\\Model \$model): void
    {
        //
    }
}
PHP,
            'middleware' => <<<PHP
final class {$class} implements \\Psr\\Http\\Server\\MiddlewareInterface
{
    public function process(\\Psr\\Http\\Message\\ServerRequestInterface \$request, \\Psr\\Http\\Server\\RequestHandlerInterface \$handler): \\Psr\\Http\\Message\\ResponseInterface
    {
        return \$handler->handle(\$request);
    }
}
PHP,
        };
        return [$path, "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\n{$body}\n"];
    }

    /** @return array{0:string,1:string} */
    private function migration(string $name): array
    {
        $snake = Str::snake(str_replace('/', '_', $name));
        $table = preg_match('/^create_(.+)_table$/', $snake, $m) ? $m[1] : 'table_name';
        $file = 'database/migrations/' . date('Y_m_d_His') . '_' . $snake . '.php';
        $code = <<<PHP
<?php

declare(strict_types=1);

use Naluz\\Database\\Migrations\\Migration;
use Naluz\\Database\\Schema\\Schema;

return new class extends Migration {
    public function up(Schema \$schema): void
    {
        \$schema->create('{$table}', function (\$t) {
            \$t->id();
            \$t->timestamps();
        });
    }

    public function down(Schema \$schema): void
    {
        \$schema->dropIfExists('{$table}');
    }
};

PHP;
        return [$file, $code];
    }
}
