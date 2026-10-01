<?php

declare(strict_types=1);

namespace Naluz\GraphQL;

use Naluz\GraphQL\Type\ListType;
use Naluz\GraphQL\Type\NonNullType;
use Naluz\GraphQL\Type\ScalarType;
use Naluz\GraphQL\Type\Type;

/** Built-in scalars and type wrappers: `Types::nonNull(Types::listOf(Types::string()))`. */
final class Types
{
    private static ?ScalarType $int = null;
    private static ?ScalarType $float = null;
    private static ?ScalarType $string = null;
    private static ?ScalarType $boolean = null;
    private static ?ScalarType $id = null;

    public static function int(): ScalarType
    {
        return self::$int ??= new ScalarType(
            'Int',
            static function (mixed $v): int {
                if (is_bool($v) || (!is_int($v) && !is_float($v) && !(is_string($v) && preg_match('/^-?\d+$/', $v)))) {
                    throw new \InvalidArgumentException('Int cannot represent a non-integer value.');
                }
                return self::int32($v, 'Int cannot represent a non 32-bit signed integer value.');
            },
            static function (mixed $v): int {
                if (is_bool($v) || (!is_int($v) && !is_float($v))) {
                    throw new \InvalidArgumentException('Int cannot represent non-integer value.');
                }
                return self::int32($v, 'Int cannot represent non 32-bit signed integer value.');
            },
            static function (array $ast): int {
                if ($ast['kind'] !== 'int') {
                    throw new \InvalidArgumentException('Int cannot represent non-integer value.');
                }
                return self::int32($ast['value'], 'Int cannot represent non 32-bit signed integer value.');
            },
            'A signed 32-bit integer.'
        );
    }

    public static function float(): ScalarType
    {
        return self::$float ??= new ScalarType(
            'Float',
            static function (mixed $v): float {
                if (is_bool($v) || (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) || !is_finite((float) $v)) {
                    throw new \InvalidArgumentException('Float cannot represent a non numeric value.');
                }
                return (float) $v;
            },
            static function (mixed $v): float {
                if (is_bool($v) || (!is_int($v) && !is_float($v)) || !is_finite((float) $v)) {
                    throw new \InvalidArgumentException('Float cannot represent non numeric value.');
                }
                return (float) $v;
            },
            null,
            'A signed double-precision floating-point value.'
        );
    }

    public static function string(): ScalarType
    {
        return self::$string ??= new ScalarType(
            'String',
            static function (mixed $v): string {
                if (is_string($v) || is_int($v) || is_float($v) || $v instanceof \Stringable) {
                    return (string) $v;
                }
                if (is_bool($v)) {
                    return $v ? 'true' : 'false';
                }
                throw new \InvalidArgumentException('String cannot represent this value.');
            },
            static function (mixed $v): string {
                if (!is_string($v)) {
                    throw new \InvalidArgumentException('String cannot represent a non string value.');
                }
                return $v;
            },
            null,
            'UTF-8 text.'
        );
    }

    public static function boolean(): ScalarType
    {
        return self::$boolean ??= new ScalarType(
            'Boolean',
            static function (mixed $v): bool {
                // databases commonly return 0 / 1 for booleans
                if (is_bool($v)) {
                    return $v;
                }
                if ($v === 0 || $v === 1 || $v === '0' || $v === '1') {
                    return (bool) (int) $v;
                }
                throw new \InvalidArgumentException('Boolean cannot represent a non boolean value.');
            },
            static function (mixed $v): bool {
                if (!is_bool($v)) {
                    throw new \InvalidArgumentException('Boolean cannot represent a non boolean value.');
                }
                return $v;
            },
            null,
            'true or false.'
        );
    }

    public static function id(): ScalarType
    {
        return self::$id ??= new ScalarType(
            'ID',
            static function (mixed $v): string {
                if (is_string($v) || is_int($v)) {
                    return (string) $v;
                }
                throw new \InvalidArgumentException('ID cannot represent this value.');
            },
            static function (mixed $v): string {
                if (is_string($v) || is_int($v)) {
                    return (string) $v;
                }
                throw new \InvalidArgumentException('ID cannot represent a value that is not a string or integer.');
            },
            null,
            'A unique identifier, serialized as a string.'
        );
    }

    /** @param Type|\Closure():Type $of */
    public static function nonNull(Type|\Closure $of): NonNullType
    {
        return new NonNullType($of);
    }

    /** @param Type|\Closure():Type $of */
    public static function listOf(Type|\Closure $of): ListType
    {
        return new ListType($of);
    }

    /**
     * A custom scalar.
     *
     * @param \Closure(mixed):mixed $serialize
     * @param \Closure(mixed):mixed $parseValue
     */
    public static function scalar(string $name, \Closure $serialize, \Closure $parseValue, ?\Closure $parseLiteral = null, ?string $description = null): ScalarType
    {
        return new ScalarType($name, $serialize, $parseValue, $parseLiteral, $description);
    }

    /** @return list<ScalarType> */
    public static function builtinScalars(): array
    {
        return [self::int(), self::float(), self::string(), self::boolean(), self::id()];
    }

    private static function int32(int|float|string $v, string $message): int
    {
        if (is_float($v) && (!is_finite($v) || floor($v) !== $v)) {
            throw new \InvalidArgumentException('Int cannot represent non-integer value.');
        }
        $n = (int) $v;
        if ($n > 2147483647 || $n < -2147483648 || (is_float($v) && abs($v) > 2147483647.0)) {
            throw new \InvalidArgumentException($message);
        }
        return $n;
    }
}
