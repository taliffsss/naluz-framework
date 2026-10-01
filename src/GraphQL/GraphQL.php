<?php

declare(strict_types=1);

namespace Naluz\GraphQL;

use Naluz\GraphQL\Language\Parser;

/**
 * The GraphQL engine: parse → validate → execute.
 *
 *     $result = $graphql->execute('{ posts { title } }', ['first' => 5], null, $request);
 *     // ['data' => …, 'errors' => […]]  (errors only when something went wrong)
 */
final class GraphQL
{
    /** @param (\Closure(\Throwable):void)|null $reporter receives unexpected exceptions (for logging); clients only see a masked message */
    public function __construct(
        private readonly Schema $schema,
        private readonly int $maxDepth = 10,
        private readonly int $maxNodes = 500,
        private readonly int $maxQueryLength = 20000,
        private readonly bool $introspection = true,
        private readonly bool $debug = false,
        private readonly ?\Closure $reporter = null
    ) {
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    public function maxQueryLength(): int
    {
        return $this->maxQueryLength;
    }

    /**
     * @param array<string,mixed> $variables
     * @param mixed $context handed to every resolver (the controller passes the PSR-7 request)
     * @return array{data?:mixed,errors?:list<array<string,mixed>>}
     */
    public function execute(string $query, array $variables = [], ?string $operationName = null, mixed $context = null, bool $allowMutations = true): array
    {
        if (strlen($query) > $this->maxQueryLength) {
            return $this->failure(new GraphQLError("The query is too long (more than {$this->maxQueryLength} bytes).", ['code' => 'QUERY_TOO_LARGE']));
        }
        try {
            $document = Parser::parse($query);
            $operation = $this->selectOperation($document, $operationName);
        } catch (GraphQLError $e) {
            return $this->failure($e);
        }

        if ($operation['type'] === 'mutation' && !$allowMutations) {
            return $this->failure(new GraphQLError('Mutations can only be sent with POST.', ['code' => 'METHOD_NOT_ALLOWED']));
        }

        $errors = (new Validator($this->schema, $this->maxDepth, $this->maxNodes, $this->introspection))->validate($document, $operation);
        if ($errors !== []) {
            return ['errors' => array_map(static fn (GraphQLError $e) => $e->toArray(), $errors)];
        }

        return (new Executor($this->schema, $this->debug, $this->reporter))->execute($document, $operation, $variables, $context);
    }

    /**
     * @param array{operations:list<array<string,mixed>>,fragments:array<string,array<string,mixed>>} $document
     * @return array<string,mixed>
     */
    private function selectOperation(array $document, ?string $name): array
    {
        $operations = $document['operations'];
        if ($operations === []) {
            throw new GraphQLError('The document contains no operation to run.');
        }
        $seen = [];
        foreach ($operations as $op) {
            if ($op['name'] === null && count($operations) > 1) {
                throw (new GraphQLError('This anonymous operation must be the only defined operation.'))->at($op['loc']['line'], $op['loc']['column']);
            }
            if ($op['name'] !== null && isset($seen[$op['name']])) {
                throw (new GraphQLError("There can be only one operation named \"{$op['name']}\"."))->at($op['loc']['line'], $op['loc']['column']);
            }
            $seen[$op['name'] ?? ''] = true;
        }
        if ($name === null || $name === '') {
            if (count($operations) > 1) {
                throw new GraphQLError('Must provide an operation name if the query contains multiple operations.');
            }
            return $operations[0];
        }
        foreach ($operations as $op) {
            if ($op['name'] === $name) {
                return $op;
            }
        }
        throw new GraphQLError("Unknown operation named \"{$name}\".");
    }

    /** @return array{errors:list<array<string,mixed>>} */
    private function failure(GraphQLError $e): array
    {
        return ['errors' => [$e->toArray()]];
    }
}
