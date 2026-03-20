<?php

declare (strict_types=1);
namespace Graph_Ql\Language;

class Source_Location implements \JsonSerializable
{
    public int $line;
    public int $column;
    public function __construct(int $line, int $col)
    {
        $this->line = $line;
        $this->column = $col;
    }
    /** @return array{line: int, column: int} */
    public function to_array(): array
    {
        return ['line' => $this->line, 'column' => $this->column];
    }
    /** @return array{line: int, column: int} */
    public function to_serializable_array(): array
    {
        return $this->to_array();
    }
    /** @return array{line: int, column: int} */
    #[\Return_Type_Will_Change]
    public function jsonSerialize(): array
    {
        return $this->to_array();
    }
}