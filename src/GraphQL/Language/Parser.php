<?php

declare(strict_types=1);

namespace Naluz\GraphQL\Language;

use Naluz\GraphQL\GraphQLError;

/**
 * Parses an executable GraphQL document (queries, mutations, fragments) into a plain-array AST.
 * Type-system (SDL) definitions are rejected: schemas are built in PHP.
 */
final class Parser
{
    private const MAX_NESTING = 64;

    /** @var list<array{0:string,1:string,2:int,3:int}> */
    private array $tokens;
    private int $pos = 0;
    private int $depth = 0;

    private function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /** @return array{operations:list<array<string,mixed>>,fragments:array<string,array<string,mixed>>} */
    public static function parse(string $source, int $maxTokens = 20000): array
    {
        return (new self(Lexer::tokenize($source, $maxTokens)))->document();
    }

    private function document(): array
    {
        $operations = [];
        $fragments = [];
        while (!$this->is('eof')) {
            if ($this->is('{')) {
                $operations[] = $this->operation(true);
            } elseif ($this->isName('query') || $this->isName('mutation') || $this->isName('subscription')) {
                $operations[] = $this->operation(false);
            } elseif ($this->isName('fragment')) {
                $f = $this->fragment();
                if (isset($fragments[$f['name']])) {
                    throw $this->errorAt($f['loc'], "There can be only one fragment named \"{$f['name']}\"");
                }
                $fragments[$f['name']] = $f;
            } else {
                throw $this->unexpected('only operations and fragments may be defined in a request');
            }
        }
        if ($operations === [] && $fragments === []) {
            throw new GraphQLError('Syntax Error: the document is empty.');
        }
        return ['operations' => $operations, 'fragments' => $fragments];
    }

    private function operation(bool $shorthand): array
    {
        $loc = $this->loc();
        if ($shorthand) {
            return ['kind' => 'operation', 'type' => 'query', 'name' => null, 'variables' => [], 'directives' => [], 'selections' => $this->selectionSet(), 'loc' => $loc];
        }
        $type = $this->next()[1];
        $name = $this->is('name') ? $this->next()[1] : null;
        $variables = [];
        if ($this->is('(')) {
            $this->next();
            while (!$this->is(')')) {
                $vloc = $this->loc();
                $this->expect('$');
                $vname = $this->expect('name')[1];
                $this->expect(':');
                $vtype = $this->typeRef();
                $var = ['name' => $vname, 'type' => $vtype, 'loc' => $vloc];
                if ($this->is('=')) {
                    $this->next();
                    $var['default'] = $this->value(true);
                }
                foreach ($variables as $other) {
                    if ($other['name'] === $vname) {
                        throw $this->errorAt($vloc, "There can be only one variable named \"\${$vname}\"");
                    }
                }
                $variables[] = $var;
            }
            $this->expect(')');
        }
        $directives = $this->directives();
        return ['kind' => 'operation', 'type' => $type, 'name' => $name, 'variables' => $variables, 'directives' => $directives, 'selections' => $this->selectionSet(), 'loc' => $loc];
    }

    private function fragment(): array
    {
        $loc = $this->loc();
        $this->next(); // fragment
        $name = $this->expect('name')[1];
        if ($name === 'on') {
            throw $this->errorAt($loc, 'Fragment cannot be named "on"');
        }
        if (!$this->isName('on')) {
            throw $this->unexpected('expected "on"');
        }
        $this->next();
        $on = $this->expect('name')[1];
        $directives = $this->directives();
        return ['name' => $name, 'on' => $on, 'directives' => $directives, 'selections' => $this->selectionSet(), 'loc' => $loc];
    }

    /** @return list<array<string,mixed>> */
    private function selectionSet(): array
    {
        if (++$this->depth > self::MAX_NESTING) {
            throw $this->errorAt($this->loc(), 'The document is nested too deeply');
        }
        $this->expect('{');
        $selections = [];
        do {
            $selections[] = $this->selection();
        } while (!$this->is('}'));
        $this->expect('}');
        $this->depth--;
        return $selections;
    }

    private function selection(): array
    {
        $loc = $this->loc();
        if ($this->is('...')) {
            $this->next();
            if ($this->is('name') && !$this->isName('on')) {
                return ['kind' => 'spread', 'name' => $this->next()[1], 'directives' => $this->directives(), 'loc' => $loc];
            }
            $on = null;
            if ($this->isName('on')) {
                $this->next();
                $on = $this->expect('name')[1];
            }
            $directives = $this->directives();
            return ['kind' => 'inline', 'on' => $on, 'directives' => $directives, 'selections' => $this->selectionSet(), 'loc' => $loc];
        }
        $name = $this->expect('name')[1];
        $alias = null;
        if ($this->is(':')) {
            $this->next();
            $alias = $name;
            $name = $this->expect('name')[1];
        }
        $args = [];
        if ($this->is('(')) {
            $this->next();
            while (!$this->is(')')) {
                $aloc = $this->loc();
                $aname = $this->expect('name')[1];
                $this->expect(':');
                if (array_key_exists($aname, $args)) {
                    throw $this->errorAt($aloc, "There can be only one argument named \"{$aname}\"");
                }
                $args[$aname] = $this->value(false);
            }
            $this->expect(')');
        }
        $directives = $this->directives();
        return [
            'kind' => 'field', 'alias' => $alias, 'name' => $name, 'args' => $args, 'directives' => $directives,
            'selections' => $this->is('{') ? $this->selectionSet() : null, 'loc' => $loc,
        ];
    }

    /** @return list<array{name:string,args:array<string,array>}> */
    private function directives(): array
    {
        $out = [];
        while ($this->is('@')) {
            $this->next();
            $name = $this->expect('name')[1];
            $args = [];
            if ($this->is('(')) {
                $this->next();
                while (!$this->is(')')) {
                    $aname = $this->expect('name')[1];
                    $this->expect(':');
                    $args[$aname] = $this->value(false);
                }
                $this->expect(')');
            }
            $out[] = ['name' => $name, 'args' => $args];
        }
        return $out;
    }

    private function value(bool $const): array
    {
        if (++$this->depth > self::MAX_NESTING) {
            throw $this->errorAt($this->loc(), 'The document is nested too deeply');
        }
        [$kind, $text] = $this->peek();
        $result = match (true) {
            $kind === '$' && !$const => (function () {
                $this->next();
                return ['kind' => 'var', 'name' => $this->expect('name')[1]];
            })(),
            $kind === '$' => throw $this->unexpected('variables are not allowed here'),
            $kind === 'int' => $this->scalar('int', (int) $this->next()[1], $text),
            $kind === 'float' => ['kind' => 'float', 'value' => (float) $this->next()[1]],
            $kind === 'string' => ['kind' => 'string', 'value' => $this->next()[1]],
            $kind === '[' => $this->listValue($const),
            $kind === '{' => $this->objectValue($const),
            $kind === 'name' => $this->nameValue(),
            default => throw $this->unexpected('expected a value'),
        };
        $this->depth--;
        return $result;
    }

    private function scalar(string $kind, int $value, string $text): array
    {
        // numbers that overflow PHP ints are kept as floats so Int coercion can reject them cleanly
        return ['kind' => (string) $value === $text || $text === '-0' ? $kind : 'float', 'value' => (string) $value === $text ? $value : (float) $text];
    }

    private function nameValue(): array
    {
        $name = $this->next()[1];
        return match ($name) {
            'true' => ['kind' => 'bool', 'value' => true],
            'false' => ['kind' => 'bool', 'value' => false],
            'null' => ['kind' => 'null'],
            default => ['kind' => 'enum', 'value' => $name],
        };
    }

    private function listValue(bool $const): array
    {
        $this->expect('[');
        $items = [];
        while (!$this->is(']')) {
            $items[] = $this->value($const);
        }
        $this->expect(']');
        return ['kind' => 'list', 'value' => $items];
    }

    private function objectValue(bool $const): array
    {
        $this->expect('{');
        $fields = [];
        while (!$this->is('}')) {
            $loc = $this->loc();
            $name = $this->expect('name')[1];
            $this->expect(':');
            if (array_key_exists($name, $fields)) {
                throw $this->errorAt($loc, "There can be only one input field named \"{$name}\"");
            }
            $fields[$name] = $this->value($const);
        }
        $this->expect('}');
        return ['kind' => 'object', 'value' => $fields];
    }

    private function typeRef(): array
    {
        if (++$this->depth > self::MAX_NESTING) {
            throw $this->errorAt($this->loc(), 'The document is nested too deeply');
        }
        if ($this->is('[')) {
            $this->next();
            $type = ['kind' => 'list', 'of' => $this->typeRef()];
            $this->expect(']');
        } else {
            $type = ['kind' => 'named', 'name' => $this->expect('name')[1]];
        }
        if ($this->is('!')) {
            $this->next();
            $type = ['kind' => 'nonnull', 'of' => $type];
        }
        $this->depth--;
        return $type;
    }

    // ------------------------------------------------------------------ token helpers

    /** @return array{0:string,1:string,2:int,3:int} */
    private function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    /** @return array{0:string,1:string,2:int,3:int} */
    private function next(): array
    {
        $t = $this->tokens[$this->pos];
        if ($t[0] !== 'eof') {
            $this->pos++;
        }
        return $t;
    }

    private function is(string $kind): bool
    {
        return $this->tokens[$this->pos][0] === $kind;
    }

    private function isName(string $name): bool
    {
        return $this->tokens[$this->pos][0] === 'name' && $this->tokens[$this->pos][1] === $name;
    }

    /** @return array{0:string,1:string,2:int,3:int} */
    private function expect(string $kind): array
    {
        if (!$this->is($kind)) {
            throw $this->unexpected("expected \"{$kind}\"");
        }
        return $this->next();
    }

    /** @return array{line:int,column:int} */
    private function loc(): array
    {
        return ['line' => $this->tokens[$this->pos][2], 'column' => $this->tokens[$this->pos][3]];
    }

    private function unexpected(string $hint): GraphQLError
    {
        $t = $this->peek();
        $what = $t[0] === 'eof' ? '<EOF>' : '"' . $t[1] . '"';
        return (new GraphQLError("Syntax Error: Unexpected {$what}; {$hint}."))->at($t[2], $t[3]);
    }

    private function errorAt(array $loc, string $message): GraphQLError
    {
        return (new GraphQLError("Syntax Error: {$message}."))->at($loc['line'], $loc['column']);
    }
}
