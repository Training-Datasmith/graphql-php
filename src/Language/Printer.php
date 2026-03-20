<?php

declare (strict_types=1);
namespace Graph_Ql\Language;

use Graph_Ql\Language\AST\Argument_Node;
use Graph_Ql\Language\AST\Boolean_Value_Node;
use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\AST\Directive_Node;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\AST\Enum_Type_Definition_Node;
use Graph_Ql\Language\AST\Enum_Type_Extension_Node;
use Graph_Ql\Language\AST\Enum_Value_Definition_Node;
use Graph_Ql\Language\AST\Enum_Value_Node;
use Graph_Ql\Language\AST\Field_Definition_Node;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Float_Value_Node;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Input_Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Input_Object_Type_Extension_Node;
use Graph_Ql\Language\AST\Input_Value_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Extension_Node;
use Graph_Ql\Language\AST\Int_Value_Node;
use Graph_Ql\Language\AST\List_Type_Node;
use Graph_Ql\Language\AST\List_Value_Node;
use Graph_Ql\Language\AST\Named_Type_Node;
use Graph_Ql\Language\AST\Name_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Node_List;
use Graph_Ql\Language\AST\Non_Null_Type_Node;
use Graph_Ql\Language\AST\Null_Value_Node;
use Graph_Ql\Language\AST\Object_Field_Node;
use Graph_Ql\Language\AST\Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Object_Type_Extension_Node;
use Graph_Ql\Language\AST\Object_Value_Node;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Operation_Type_Definition_Node;
use Graph_Ql\Language\AST\Scalar_Type_Definition_Node;
use Graph_Ql\Language\AST\Scalar_Type_Extension_Node;
use Graph_Ql\Language\AST\Schema_Definition_Node;
use Graph_Ql\Language\AST\Schema_Extension_Node;
use Graph_Ql\Language\AST\Selection_Set_Node;
use Graph_Ql\Language\AST\String_Value_Node;
use Graph_Ql\Language\AST\Union_Type_Definition_Node;
use Graph_Ql\Language\AST\Union_Type_Extension_Node;
use Graph_Ql\Language\AST\Variable_Definition_Node;
use Graph_Ql\Language\AST\Variable_Node;
/**
 * Prints AST to string. Capable of printing GraphQL queries and Type definition language.
 * Useful for pretty-printing queries or printing back AST for logging, documentation, etc.
 *
 * Usage example:
 *
 * ```php
 * $query = 'query myQuery {someField}';
 * $ast = GraphQL\Language\Parser::parse($query);
 * $printed = GraphQL\Language\Printer::doPrint($ast);
 * ```
 *
 * @see \GraphQL\Tests\Language\PrinterTest
 */
class Printer
{
    /**
     * Converts the AST of a GraphQL node to a string.
     *
     * Handles both executable definitions and schema definitions.
     *
     * @throws \JsonException
     *
     * @api
     */
    public static function do_print(Node $ast): string
    {
        return static::p($ast);
    }
    /** @throws \JsonException */
    protected static function p(?Node $node): string
    {
        if ($node === null) {
            return '';
        }
        switch (true) {
            case $node instanceof Argument_Node:
            case $node instanceof Object_Field_Node:
                return static::p($node->name) . ': ' . static::p($node->value);
            case $node instanceof Boolean_Value_Node:
                return $node->value ? 'true' : 'false';
            case $node instanceof Directive_Definition_Node:
                $arg_strings = [];
                foreach ($node->arguments as $arg) {
                    $arg_strings[] = static::p($arg);
                }
                $no_indent = true;
                foreach ($arg_strings as $arg_string) {
                    if (strpos($arg_string, "\n") !== false) {
                        $no_indent = false;
                        break;
                    }
                }
                return static::add_description($node->description, 'directive @' . static::p($node->name) . ($no_indent ? static::wrap('(', static::join($arg_strings, ', '), ')') : static::wrap("(\n", static::indent(static::join($arg_strings, "\n")), "\n")) . ($node->repeatable ? ' repeatable' : '') . ' on ' . static::print_list($node->locations, ' | '));
            case $node instanceof Directive_Node:
                return '@' . static::p($node->name) . static::wrap('(', static::print_list($node->arguments, ', '), ')');
            case $node instanceof Document_Node:
                return static::print_list($node->definitions, "\n\n") . "\n";
            case $node instanceof Enum_Type_Definition_Node:
                return static::add_description($node->description, static::join(['enum', static::p($node->name), static::print_list($node->directives, ' '), static::print_list_block($node->values)], ' '));
            case $node instanceof Enum_Type_Extension_Node:
                return static::join(['extend enum', static::p($node->name), static::print_list($node->directives, ' '), static::print_list_block($node->values)], ' ');
            case $node instanceof Enum_Value_Definition_Node:
                return static::add_description($node->description, static::join([static::p($node->name), static::print_list($node->directives, ' ')], ' '));
            case $node instanceof Enum_Value_Node:
            case $node instanceof Float_Value_Node:
            case $node instanceof Int_Value_Node:
            case $node instanceof Name_Node:
                return $node->value;
            case $node instanceof Field_Definition_Node:
                $arg_strings = [];
                foreach ($node->arguments as $item) {
                    $arg_strings[] = static::p($item);
                }
                $no_indent = true;
                foreach ($arg_strings as $arg_string) {
                    if (strpos($arg_string, "\n") !== false) {
                        $no_indent = false;
                        break;
                    }
                }
                return static::add_description($node->description, static::p($node->name) . ($no_indent ? static::wrap('(', static::join($arg_strings, ', '), ')') : static::wrap("(\n", static::indent(static::join($arg_strings, "\n")), "\n)")) . ': ' . static::p($node->type) . static::wrap(' ', static::print_list($node->directives, ' ')));
            case $node instanceof Field_Node:
                $prefix = static::wrap('', $node->alias->value ?? null, ': ') . static::p($node->name);
                $args_line = $prefix . static::wrap('(', static::print_list($node->arguments, ', '), ')');
                if (strlen($args_line) > 80) {
                    $args_line = $prefix . static::wrap("(\n", static::indent(static::print_list($node->arguments, "\n")), "\n)");
                }
                return static::join([$args_line, static::print_list($node->directives, ' '), static::p($node->selection_set)], ' ');
            case $node instanceof Fragment_Definition_Node:
                // Note: fragment variable definitions are experimental and may be changed or removed in the future.
                return 'fragment ' . static::p($node->name) . static::wrap('(', static::print_list($node->variable_definitions ?? new Node_List([]), ', '), ')') . ' on ' . static::p($node->type_condition->name) . ' ' . static::wrap('', static::print_list($node->directives, ' '), ' ') . static::p($node->selection_set);
            case $node instanceof Fragment_Spread_Node:
                return '...' . static::p($node->name) . static::wrap(' ', static::print_list($node->directives, ' '));
            case $node instanceof Inline_Fragment_Node:
                return static::join(['...', static::wrap('on ', static::p($node->type_condition->name ?? null)), static::print_list($node->directives, ' '), static::p($node->selection_set)], ' ');
            case $node instanceof Input_Object_Type_Definition_Node:
                return static::add_description($node->description, static::join(['input', static::p($node->name), static::print_list($node->directives, ' '), static::print_list_block($node->fields)], ' '));
            case $node instanceof Input_Object_Type_Extension_Node:
                return static::join(['extend input', static::p($node->name), static::print_list($node->directives, ' '), static::print_list_block($node->fields)], ' ');
            case $node instanceof Input_Value_Definition_Node:
                return static::add_description($node->description, static::join([static::p($node->name) . ': ' . static::p($node->type), static::wrap('= ', static::p($node->default_value)), static::print_list($node->directives, ' ')], ' '));
            case $node instanceof Interface_Type_Definition_Node:
                return static::add_description($node->description, static::join(['interface', static::p($node->name), static::wrap('implements ', static::print_list($node->interfaces, ' & ')), static::print_list($node->directives, ' '), static::print_list_block($node->fields)], ' '));
            case $node instanceof Interface_Type_Extension_Node:
                return static::join(['extend interface', static::p($node->name), static::wrap('implements ', static::print_list($node->interfaces, ' & ')), static::print_list($node->directives, ' '), static::print_list_block($node->fields)], ' ');
            case $node instanceof List_Type_Node:
                return '[' . static::p($node->type) . ']';
            case $node instanceof List_Value_Node:
                return '[' . static::print_list($node->values, ', ') . ']';
            case $node instanceof Named_Type_Node:
                return static::p($node->name);
            case $node instanceof Non_Null_Type_Node:
                return static::p($node->type) . '!';
            case $node instanceof Null_Value_Node:
                return 'null';
            case $node instanceof Object_Type_Definition_Node:
                return static::add_description($node->description, static::join(['type', static::p($node->name), static::wrap('implements ', static::print_list($node->interfaces, ' & ')), static::print_list($node->directives, ' '), static::print_list_block($node->fields)], ' '));
            case $node instanceof Object_Type_Extension_Node:
                return static::join(['extend type', static::p($node->name), static::wrap('implements ', static::print_list($node->interfaces, ' & ')), static::print_list($node->directives, ' '), static::print_list_block($node->fields)], ' ');
            case $node instanceof Object_Value_Node:
                return '{ ' . static::print_list($node->fields, ', ') . ' }';
            case $node instanceof Operation_Definition_Node:
                $op = $node->operation;
                $name = static::p($node->name);
                $var_defs = static::wrap('(', static::print_list($node->variable_definitions, ', '), ')');
                $directives = static::print_list($node->directives, ' ');
                $selection_set = static::p($node->selection_set);
                // Anonymous queries with no directives or variable definitions can use
                // the query short form.
                return $name === '' && $directives === '' && $var_defs === '' && $op === 'query' ? $selection_set : static::join([$op, static::join([$name, $var_defs]), $directives, $selection_set], ' ');
            case $node instanceof Operation_Type_Definition_Node:
                return $node->operation . ': ' . static::p($node->type);
            case $node instanceof Scalar_Type_Definition_Node:
                return static::add_description($node->description, static::join(['scalar', static::p($node->name), static::print_list($node->directives, ' ')], ' '));
            case $node instanceof Scalar_Type_Extension_Node:
                return static::join(['extend scalar', static::p($node->name), static::print_list($node->directives, ' ')], ' ');
            case $node instanceof Schema_Definition_Node:
                return static::add_description($node->description, static::join(['schema', static::print_list($node->directives, ' '), static::print_list_block($node->operation_types)], ' '));
            case $node instanceof Schema_Extension_Node:
                return static::join(['extend schema', static::print_list($node->directives, ' '), static::print_list_block($node->operation_types)], ' ');
            case $node instanceof Selection_Set_Node:
                return static::print_list_block($node->selections);
            case $node instanceof String_Value_Node:
                if ($node->block) {
                    return Block_String::print($node->value);
                }
                return json_encode($node->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            case $node instanceof Union_Type_Definition_Node:
                $types_str = static::print_list($node->types, ' | ');
                return static::add_description($node->description, static::join(['union', static::p($node->name), static::print_list($node->directives, ' '), $types_str !== '' ? "= {$types_str}" : ''], ' '));
            case $node instanceof Union_Type_Extension_Node:
                $types_str = static::print_list($node->types, ' | ');
                return static::join(['extend union', static::p($node->name), static::print_list($node->directives, ' '), $types_str !== '' ? "= {$types_str}" : ''], ' ');
            case $node instanceof Variable_Definition_Node:
                return '$' . static::p($node->variable->name) . ': ' . static::p($node->type) . static::wrap(' = ', static::p($node->default_value)) . static::wrap(' ', static::print_list($node->directives, ' '));
            case $node instanceof Variable_Node:
                return '$' . static::p($node->name);
        }
        return '';
    }
    /**
     * @template TNode of Node
     *
     * @param NodeList<TNode> $list
     *
     * @throws \JsonException
     */
    protected static function print_list(Node_List $list, string $separator = ''): string
    {
        $parts = [];
        foreach ($list as $item) {
            $parts[] = static::p($item);
        }
        return static::join($parts, $separator);
    }
    /**
     * Print each item on its own line, wrapped in an indented "{ }" block.
     *
     * @template TNode of Node
     *
     * @param NodeList<TNode> $list
     *
     * @throws \JsonException
     */
    protected static function print_list_block(Node_List $list): string
    {
        if (count($list) === 0) {
            return '';
        }
        $parts = [];
        foreach ($list as $item) {
            $parts[] = static::p($item);
        }
        return "{\n" . static::indent(static::join($parts, "\n")) . "\n}";
    }
    /** @throws \JsonException */
    protected static function add_description(?String_Value_Node $description, string $body): string
    {
        return static::join([static::p($description), $body], "\n");
    }
    /**
     * If maybeString is not null or empty, then wrap with start and end, otherwise
     * print an empty string.
     */
    protected static function wrap(string $start, ?string $maybe_string, string $end = ''): string
    {
        if ($maybe_string === null || $maybe_string === '') {
            return '';
        }
        return $start . $maybe_string . $end;
    }
    protected static function indent(string $string): string
    {
        if ($string === '') {
            return '';
        }
        return '  ' . str_replace("\n", "\n  ", $string);
    }
    /** @param array<string|null> $parts */
    protected static function join(array $parts, string $separator = ''): string
    {
        return implode($separator, array_filter($parts, static fn(?string $part): bool => $part !== '' && $part !== null));
    }
}