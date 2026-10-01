<?php

declare(strict_types=1);

namespace Naluz\GraphQL;

use Naluz\Http\Response;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The HTTP endpoint. Route it with `$router->match(['GET', 'POST'], '/graphql', [GraphQLController::class, 'handle'])`.
 *
 * POST accepts `application/json` (`{"query", "variables", "operationName"}`) or `application/graphql` (the body is the query).
 * GET accepts `?query=…&variables={…}&operationName=…` for queries only; mutations over GET are refused.
 * Batched requests (a JSON array) are not supported: they let one request multiply the work.
 */
final class GraphQLController
{
    private const JSON_DEPTH = 32;

    public function __construct(private readonly GraphQL $graphql)
    {
    }

    public function handle(ServerRequestInterface $request): Response
    {
        $method = strtoupper($request->getMethod());
        try {
            [$query, $variables, $operation] = $method === 'POST' ? $this->fromBody($request) : $this->fromQuery($request);
        } catch (GraphQLError $e) {
            return $this->respond(['errors' => [$e->toArray()]], 400);
        }

        $result = $this->graphql->execute($query, $variables, $operation, $request, $method === 'POST');

        $status = 200;
        if (!array_key_exists('data', $result)) {
            // the request failed before anything ran (syntax, validation, limits)
            $code = $result['errors'][0]['extensions']['code'] ?? null;
            $status = $code === 'METHOD_NOT_ALLOWED' ? 405 : 400;
        }
        return $this->respond($result, $status, $status === 405 ? ['Allow' => 'POST'] : []);
    }

    /** @return array{0:string,1:array<string,mixed>,2:?string} */
    private function fromBody(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        if (strlen($raw) > $this->graphql->maxQueryLength() + 65536) {
            throw new GraphQLError('The request body is too large.', ['code' => 'REQUEST_TOO_LARGE']);
        }
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));

        if ($type === 'application/graphql') {
            return [$raw, [], null];
        }
        if ($type !== 'application/json') {
            throw new GraphQLError('Send the request as application/json (or application/graphql).', ['code' => 'UNSUPPORTED_MEDIA_TYPE']);
        }
        try {
            $body = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new GraphQLError('The request body is not valid JSON.', ['code' => 'BAD_REQUEST']);
        }
        if (!is_array($body) || array_is_list($body)) {
            throw new GraphQLError('The request body must be a JSON object. Batched requests are not supported.', ['code' => 'BAD_REQUEST']);
        }
        return $this->normalise($body['query'] ?? null, $body['variables'] ?? null, $body['operationName'] ?? null);
    }

    /** @return array{0:string,1:array<string,mixed>,2:?string} */
    private function fromQuery(ServerRequestInterface $request): array
    {
        $q = $request->getQueryParams();
        $variables = $q['variables'] ?? null;
        if (is_string($variables) && $variables !== '') {
            try {
                $variables = json_decode($variables, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new GraphQLError('The "variables" parameter is not valid JSON.', ['code' => 'BAD_REQUEST']);
            }
        } elseif ($variables === '') {
            $variables = null;
        }
        return $this->normalise($q['query'] ?? null, $variables, $q['operationName'] ?? null);
    }

    /** @return array{0:string,1:array<string,mixed>,2:?string} */
    private function normalise(mixed $query, mixed $variables, mixed $operation): array
    {
        if (!is_string($query) || trim($query) === '') {
            throw new GraphQLError('Provide a GraphQL query in the "query" field.', ['code' => 'BAD_REQUEST']);
        }
        if ($variables !== null && (!is_array($variables) || ($variables !== [] && array_is_list($variables)))) {
            throw new GraphQLError('"variables" must be a JSON object.', ['code' => 'BAD_REQUEST']);
        }
        if ($operation !== null && !is_string($operation)) {
            throw new GraphQLError('"operationName" must be a string.', ['code' => 'BAD_REQUEST']);
        }
        return [$query, $variables ?? [], $operation];
    }

    /** @param array<string,mixed> $payload @param array<string,string> $headers */
    private function respond(array $payload, int $status, array $headers = []): Response
    {
        return Response::json($payload, $status, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'] + $headers);
    }
}
