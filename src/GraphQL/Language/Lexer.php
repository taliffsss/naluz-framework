<?php

declare(strict_types=1);

namespace Naluz\GraphQL\Language;

use Naluz\GraphQL\GraphQLError;

/** Turns a GraphQL document into tokens: `[kind, value, line, column]`. */
final class Lexer
{
    private const PUNCTUATORS = ['!', '$', '&', '(', ')', ':', '=', '@', '[', ']', '{', '|', '}'];

    /** @return list<array{0:string,1:string,2:int,3:int}> */
    public static function tokenize(string $source, int $maxTokens = 20000): array
    {
        if (!mb_check_encoding($source, 'UTF-8')) {
            throw new GraphQLError('Syntax Error: the document is not valid UTF-8.');
        }
        $tokens = [];
        $len = strlen($source);
        $i = 0;
        $line = 1;
        $lineStart = 0;

        while ($i < $len) {
            $c = $source[$i];
            $col = $i - $lineStart + 1;

            if ($c === "\n") {
                $line++;
                $i++;
                $lineStart = $i;
                continue;
            }
            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === ',') {
                $i++;
                continue;
            }
            if ($c === "\xEF" && substr($source, $i, 3) === "\xEF\xBB\xBF") {
                $i += 3; // BOM
                continue;
            }
            if ($c === '#') {
                while ($i < $len && $source[$i] !== "\n" && $source[$i] !== "\r") {
                    $i++;
                }
                continue;
            }
            if (count($tokens) >= $maxTokens) {
                throw new GraphQLError("Syntax Error: the document is too large (more than {$maxTokens} tokens).");
            }
            if ($c === '.') {
                if (substr($source, $i, 3) !== '...') {
                    throw self::error("Unexpected character \".\"", $line, $col);
                }
                $tokens[] = ['...', '...', $line, $col];
                $i += 3;
                continue;
            }
            if (in_array($c, self::PUNCTUATORS, true)) {
                $tokens[] = [$c, $c, $line, $col];
                $i++;
                continue;
            }
            if ($c === '_' || ctype_alpha($c)) {
                $start = $i;
                while ($i < $len && ($source[$i] === '_' || ctype_alnum($source[$i]))) {
                    $i++;
                }
                $tokens[] = ['name', substr($source, $start, $i - $start), $line, $col];
                continue;
            }
            if ($c === '-' || ctype_digit($c)) {
                $start = $i;
                $float = false;
                if ($c === '-') {
                    $i++;
                }
                if ($i >= $len || !ctype_digit($source[$i])) {
                    throw self::error('Invalid number, expected a digit', $line, $col);
                }
                if ($source[$i] === '0' && $i + 1 < $len && ctype_digit($source[$i + 1])) {
                    throw self::error('Invalid number, unexpected digit after 0', $line, $col);
                }
                while ($i < $len && ctype_digit($source[$i])) {
                    $i++;
                }
                if ($i < $len && $source[$i] === '.') {
                    $float = true;
                    $i++;
                    if ($i >= $len || !ctype_digit($source[$i])) {
                        throw self::error('Invalid number, expected a digit after "."', $line, $col);
                    }
                    while ($i < $len && ctype_digit($source[$i])) {
                        $i++;
                    }
                }
                if ($i < $len && ($source[$i] === 'e' || $source[$i] === 'E')) {
                    $float = true;
                    $i++;
                    if ($i < $len && ($source[$i] === '+' || $source[$i] === '-')) {
                        $i++;
                    }
                    if ($i >= $len || !ctype_digit($source[$i])) {
                        throw self::error('Invalid number, expected a digit in the exponent', $line, $col);
                    }
                    while ($i < $len && ctype_digit($source[$i])) {
                        $i++;
                    }
                }
                if ($i < $len && ($source[$i] === '.' || $source[$i] === '_' || ctype_alpha($source[$i]))) {
                    throw self::error('Invalid number, unexpected character after the number', $line, $col);
                }
                $tokens[] = [$float ? 'float' : 'int', substr($source, $start, $i - $start), $line, $col];
                continue;
            }
            if ($c === '"') {
                if (substr($source, $i, 3) === '"""') {
                    [$value, $i, $newLines, $lineStartDelta] = self::blockString($source, $i, $line, $col);
                    $tokens[] = ['string', $value, $line, $col];
                    if ($newLines > 0) {
                        $line += $newLines;
                        $lineStart = $lineStartDelta;
                    }
                    continue;
                }
                [$value, $i] = self::string($source, $i, $line, $col);
                $tokens[] = ['string', $value, $line, $col];
                continue;
            }
            throw self::error('Unexpected character ' . (ctype_print($c) ? "\"{$c}\"" : 'in the document'), $line, $col);
        }
        $tokens[] = ['eof', '', $line, $i - $lineStart + 1];
        return $tokens;
    }

    /** @return array{0:string,1:int} */
    private static function string(string $s, int $i, int $line, int $col): array
    {
        $len = strlen($s);
        $i++;
        $out = '';
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '"') {
                return [$out, $i + 1];
            }
            if ($c === "\n" || $c === "\r") {
                break;
            }
            if ($c === '\\') {
                $n = $s[$i + 1] ?? '';
                $map = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\x0C", 'n' => "\n", 'r' => "\r", 't' => "\t"];
                if (isset($map[$n])) {
                    $out .= $map[$n];
                    $i += 2;
                    continue;
                }
                if ($n === 'u' && preg_match('/^[0-9a-fA-F]{4}$/', substr($s, $i + 2, 4))) {
                    $code = hexdec(substr($s, $i + 2, 4));
                    if ($code >= 0xD800 && $code <= 0xDFFF) {
                        throw self::error('Surrogate pairs in \\u escapes are not supported', $line, $col);
                    }
                    $out .= mb_chr((int) $code, 'UTF-8');
                    $i += 6;
                    continue;
                }
                throw self::error('Invalid character escape sequence in string', $line, $col);
            }
            $out .= $c;
            $i++;
        }
        throw self::error('Unterminated string', $line, $col);
    }

    /** @return array{0:string,1:int,2:int,3:int} value, next index, newline count, index after the last newline */
    private static function blockString(string $s, int $i, int $line, int $col): array
    {
        $len = strlen($s);
        $i += 3;
        $raw = '';
        $newLines = 0;
        $lastNl = 0;
        while ($i < $len) {
            if (substr($s, $i, 3) === '"""') {
                return [self::dedent($raw), $i + 3, $newLines, $lastNl];
            }
            if (substr($s, $i, 4) === '\\"""') {
                $raw .= '"""';
                $i += 4;
                continue;
            }
            if ($s[$i] === "\n") {
                $newLines++;
                $lastNl = $i + 1;
            }
            $raw .= $s[$i];
            $i++;
        }
        throw self::error('Unterminated block string', $line, $col);
    }

    private static function dedent(string $raw): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
        $common = null;
        foreach (array_slice($lines, 1) as $l) {
            $indent = strlen($l) - strlen(ltrim($l, " \t"));
            if ($indent < strlen($l) && ($common === null || $indent < $common)) {
                $common = $indent;
            }
        }
        if ($common) {
            foreach ($lines as $k => $l) {
                if ($k > 0) {
                    $lines[$k] = substr($l, min($common, strlen($l)));
                }
            }
        }
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while ($lines !== [] && trim($lines[array_key_last($lines)]) === '') {
            array_pop($lines);
        }
        return implode("\n", $lines);
    }

    private static function error(string $message, int $line, int $col): GraphQLError
    {
        return (new GraphQLError("Syntax Error: {$message}."))->at($line, $col);
    }
}
