<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

/**
 * Computes the lexical distance between strings A and B.
 *
 * The "distance" between two strings is given by counting the minimum number
 * of edits needed to transform string A into string B. An edit can be an
 * insertion, deletion, or substitution of a single character, or a swap of two
 * adjacent characters.
 *
 * Includes a custom alteration from Damerau-Levenshtein to treat case changes
 * as a single edit which helps identify mis-cased values with an edit distance
 * of 1.
 *
 * This distance can be useful for detecting typos in input or sorting
 *
 * Unlike the native levenshtein() function that always returns int, LexicalDistance::measure() returns int|null.
 * It takes into account the threshold and returns null if the measured distance is bigger.
 */
class Lexical_Distance
{
    private string $input;
    private string $input_lower_case;
    /**
     * List of char codes in the input string.
     *
     * @var array<int>
     */
    private array $input_array;
    public function __construct(string $input)
    {
        $this->input = $input;
        $this->input_lower_case = strtolower($input);
        $this->input_array = self::string_to_array($this->input_lower_case);
    }
    public function measure(string $option, float $threshold): ?int
    {
        if ($this->input === $option) {
            return 0;
        }
        $option_lower_case = strtolower($option);
        // Any case change counts as a single edit
        if ($this->input_lower_case === $option_lower_case) {
            return 1;
        }
        $a = self::string_to_array($option_lower_case);
        $b = $this->input_array;
        if (count($a) < count($b)) {
            $tmp = $a;
            $a = $b;
            $b = $tmp;
        }
        $a_length = count($a);
        $b_length = count($b);
        if ($a_length - $b_length > $threshold) {
            return null;
        }
        /** @var array<array<int>> $rows */
        $rows = [];
        for ($i = 0; $i <= $b_length; ++$i) {
            $rows[0][$i] = $i;
        }
        for ($i = 1; $i <= $a_length; ++$i) {
            $up_row =& $rows[($i - 1) % 3];
            $current_row =& $rows[$i % 3];
            $smallest_cell = $current_row[0] = $i;
            for ($j = 1; $j <= $b_length; ++$j) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $current_cell = min(
                    $up_row[$j] + 1,
                    // delete
                    $current_row[$j - 1] + 1,
                    // insert
                    $up_row[$j - 1] + $cost
                );
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    // transposition
                    $double_diagonal_cell = $rows[($i - 2) % 3][$j - 2];
                    $current_cell = min($current_cell, $double_diagonal_cell + 1);
                }
                if ($current_cell < $smallest_cell) {
                    $smallest_cell = $current_cell;
                }
                $current_row[$j] = $current_cell;
            }
            // Early exit, since distance can't go smaller than smallest element of the previous row.
            if ($smallest_cell > $threshold) {
                return null;
            }
        }
        $distance = $rows[$a_length % 3][$b_length];
        return $distance <= $threshold ? $distance : null;
    }
    /**
     * Returns a list of char codes in the given string.
     *
     * @return array<int>
     */
    private static function string_to_array(string $str): array
    {
        $array = [];
        foreach (mb_str_split($str) as $char) {
            $array[] = mb_ord($char);
        }
        return $array;
    }
}