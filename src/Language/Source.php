<?php

declare (strict_types=1);
namespace Graph_Ql\Language;

class Source
{
    public string $body;
    public int $length;
    public string $name;
    public Source_Location $location_offset;
    /**
     * A representation of source input to GraphQL.
     *
     * `name` and `locationOffset` are optional. They are useful for clients who
     * store GraphQL documents in source files; for example, if the GraphQL input
     * starts at line 40 in a file named Foo.graphql, it might be useful for name to
     * be "Foo.graphql" and location to be `{ line: 40, column: 0 }`.
     * line and column in locationOffset are 1-indexed
     */
    public function __construct(string $body, ?string $name = null, ?Source_Location $location = null)
    {
        $this->body = $body;
        $this->length = mb_strlen($body, 'UTF-8');
        $this->name = $name === '' || $name === null ? 'GraphQL request' : $name;
        $this->location_offset = $location ?? new Source_Location(1, 1);
    }
    public function get_location(int $position): Source_Location
    {
        $line = 1;
        $column = $position + 1;
        $utf_chars = json_decode('"\u2028\u2029"');
        $line_regexp = '/\r\n|[\n\r' . $utf_chars . ']/su';
        $matches = [];
        preg_match_all($line_regexp, mb_substr($this->body, 0, $position, 'UTF-8'), $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $match) {
            ++$line;
            $column = $position + 1 - ($match[1] + mb_strlen($match[0], 'UTF-8'));
        }
        return new Source_Location($line, $column);
    }
}