<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

use Graph_Ql\Language\Source;
use Graph_Ql\Language\Token;
/**
 * Contains a range of UTF-8 character offsets and token references that
 * identify the region of the source from which the AST derived.
 *
 * @phpstan-type LocationArray array{start: int, end: int}
 */
class Location
{
    /** The character offset at which this Node begins. */
    public int $start;
    /** The character offset at which this Node ends. */
    public int $end;
    /** The Token at which this Node begins. */
    public ?Token $start_token = null;
    /** The Token at which this Node ends. */
    public ?Token $end_token = null;
    /** The Source document the AST represents. */
    public ?Source $source = null;
    public static function create(int $start, int $end): self
    {
        $tmp = new static();
        $tmp->start = $start;
        $tmp->end = $end;
        return $tmp;
    }
    public function __construct(?Token $start_token = null, ?Token $end_token = null, ?Source $source = null)
    {
        $this->start_token = $start_token;
        $this->end_token = $end_token;
        $this->source = $source;
        if ($start_token === null || $end_token === null) {
            return;
        }
        $this->start = $start_token->start;
        $this->end = $end_token->end;
    }
    /** @return LocationArray */
    public function to_array(): array
    {
        return ['start' => $this->start, 'end' => $this->end];
    }
}