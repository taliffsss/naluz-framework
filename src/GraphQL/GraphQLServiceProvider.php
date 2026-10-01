<?php

declare(strict_types=1);

namespace Naluz\GraphQL;

use Naluz\Config\Repository;
use Naluz\Foundation\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Reads `config/graphql.php`:
 *
 *     'schema' => App\GraphQL\AppSchema::class,   // a class with `public static function build(): Schema`, or a Closure
 *     'max_depth' => 10, 'max_nodes' => 500, 'max_query_length' => 20000,
 *     'introspection' => null,                    // null = on only when app.debug is on
 */
final class GraphQLServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Schema::class, function ($c): Schema {
            $source = $c->make(Repository::class)->get('graphql.schema');
            $schema = match (true) {
                $source instanceof Schema => $source,
                $source instanceof \Closure => $source($c),
                is_string($source) && is_callable([$source, 'build']) => $source::build(),
                default => throw new \LogicException('GraphQL is not configured: set "schema" in config/graphql.php to a class with a static build(): Schema method.'),
            };
            if (!$schema instanceof Schema) {
                throw new \LogicException('The GraphQL schema factory must return a ' . Schema::class . '.');
            }
            return $schema;
        });

        $this->app->singleton(GraphQL::class, function ($c): GraphQL {
            $config = $c->make(Repository::class);
            $debug = (bool) $config->get('app.debug', false);
            $logger = $c->make(LoggerInterface::class);
            return new GraphQL(
                $c->make(Schema::class),
                (int) $config->get('graphql.max_depth', 10),
                (int) $config->get('graphql.max_nodes', 500),
                (int) $config->get('graphql.max_query_length', 20000),
                (bool) ($config->get('graphql.introspection') ?? $debug),
                $debug,
                // log what went wrong (no stack trace, no request data); clients only see "Internal server error."
                static fn (\Throwable $e) => $logger->error('GraphQL resolver failed: ' . $e::class . ': ' . $e->getMessage())
            );
        });
    }
}
