<?php

declare (strict_types=1);
namespace Graph_Ql\Language;

use Graph_Ql\Utils\Utils;
/**
 * @see \GraphQL\Tests\Language\BlockStringTest
 */
class Block_String
{
    /**
     * Produces the value of a block string from its parsed raw value, similar to
     * CoffeeScript's block string, Python's docstring trim or Ruby's strip_heredoc.
     *
     * This implements the GraphQL spec's BlockStringValue() static algorithm.
     */
    public static function dedent_block_string_lines(string $raw_string): string
    {
        $lines = Utils::split_lines($raw_string);
        // Remove common indentation from all lines but first.
        $common_indent = self::get_indentation($raw_string);
        $lines_length = count($lines);
        if ($common_indent > 0) {
            for ($i = 1; $i < $lines_length; ++$i) {
                $lines[$i] = mb_substr($lines[$i], $common_indent);
            }
        }
        // Remove leading and trailing blank lines.
        $start_line = 0;
        while ($start_line < $lines_length && self::is_blank($lines[$start_line])) {
            ++$start_line;
        }
        $end_line = $lines_length;
        while ($end_line > $start_line && self::is_blank($lines[$end_line - 1])) {
            --$end_line;
        }
        // Return a string of the lines joined with U+000A.
        return implode("\n", array_slice($lines, $start_line, $end_line - $start_line));
    }
    private static function is_blank(string $str): bool
    {
        $str_length = mb_strlen($str);
        for ($i = 0; $i < $str_length; ++$i) {
            if ($str[$i] !== ' ' && $str[$i] !== '\t') {
                return false;
            }
        }
        return true;
    }
    public static function get_indentation(string $value): int
    {
        $is_first_line = true;
        $is_empty_line = true;
        $indent = 0;
        $common_indent = null;
        $value_length = mb_strlen($value);
        for ($i = 0; $i < $value_length; ++$i) {
            switch (Utils::char_code_at($value, $i)) {
                case 13:
                    //  \r
                    if (Utils::char_code_at($value, $i + 1) === 10) {
                        ++$i;
                        // skip \r\n as one symbol
                    }
                // falls through
                // no break
                case 10:
                    //  \n
                    $is_first_line = false;
                    $is_empty_line = true;
                    $indent = 0;
                    break;
                case 9:
                //   \t
                case 32:
                    //  <space>
                    ++$indent;
                    break;
                default:
                    if ($is_empty_line && !$is_first_line && ($common_indent === null || $indent < $common_indent)) {
                        $common_indent = $indent;
                    }
                    $is_empty_line = false;
            }
        }
        return $common_indent ?? 0;
    }
    /**
     * Print a block string in the indented block form by adding a leading and
     * trailing blank line. However, if a block string starts with whitespace and is
     * a single-line, adding a leading blank line would strip that whitespace.
     */
    public static function print(string $value): string
    {
        $escaped_value = str_replace('"""', '\"""', $value);
        // Expand a block string's raw value into independent lines.
        $lines = Utils::split_lines($escaped_value);
        $is_single_line = count($lines) === 1;
        // If common indentation is found we can fix some of those cases by adding leading new line
        $force_leading_new_line = count($lines) > 1;
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue;
            }
            if ($line !== '' && preg_match('/^\s/', $line) !== 1) {
                $force_leading_new_line = false;
            }
        }
        // Trailing triple quotes just looks confusing but doesn't force trailing new line
        $has_trailing_triple_quotes = preg_match('/\\\\"""$/', $escaped_value) === 1;
        // Trailing quote (single or double) or slash forces trailing new line
        $has_trailing_quote = preg_match('/"$/', $value) === 1 && !$has_trailing_triple_quotes;
        $has_trailing_slash = preg_match('/\\\\$/', $value) === 1;
        $force_trailing_newline = $has_trailing_quote || $has_trailing_slash;
        // add leading and trailing new lines only if it improves readability
        $print_as_multiple_lines = !$is_single_line || mb_strlen($value) > 70 || $force_trailing_newline || $force_leading_new_line || $has_trailing_triple_quotes;
        $result = '';
        // Format a multi-line block quote to account for leading space.
        $skip_leading_new_line = $is_single_line && preg_match('/^\s/', $value) === 1;
        if ($print_as_multiple_lines && !$skip_leading_new_line || $force_leading_new_line) {
            $result .= "\n";
        }
        $result .= $escaped_value;
        if ($print_as_multiple_lines) {
            $result .= "\n";
        }
        return '"""' . $result . '"""';
    }
}