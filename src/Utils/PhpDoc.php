<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

class Php_Doc
{
    /** @param string|false|null $docBlock */
    public static function unwrap($doc_block): ?string
    {
        if ($doc_block === false || $doc_block === null) {
            return null;
        }
        $content = preg_replace('~([\r\n]) \* (.*)~i', '$1$2', $doc_block);
        // strip *
        assert(is_string($content), 'regex is statically known to be valid');
        $content = preg_replace('~([\r\n])[\* ]+([\r\n])~i', '$1$2', $content);
        // strip single-liner *
        assert(is_string($content), 'regex is statically known to be valid');
        $content = substr($content, 3);
        // strip leading /**
        $content = substr($content, 0, -2);
        // strip trailing */
        return static::non_empty_or_null($content);
    }
    /** @param string|false|null $docBlock */
    public static function unpad($doc_block): ?string
    {
        if ($doc_block === false || $doc_block === null) {
            return null;
        }
        $lines = explode("\n", $doc_block);
        $lines = array_map(static fn(string $line): string => ' ' . trim($line), $lines);
        $content = implode("\n", $lines);
        return static::non_empty_or_null($content);
    }
    protected static function non_empty_or_null(string $maybe_empty_string): ?string
    {
        $trimmed = trim($maybe_empty_string);
        return $trimmed === '' ? null : $trimmed;
    }
}