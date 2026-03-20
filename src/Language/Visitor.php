<?php

declare (strict_types=1);
namespace Graph_Ql\Language;

use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Node_List;
use Graph_Ql\Utils\Type_Info;
use Graph_Ql\Utils\Utils;
/**
 * Utility for efficient AST traversal and modification.
 *
 * `visit()` will walk through an AST using a depth first traversal, calling
 * the visitor's enter function at each node in the traversal, and calling the
 * leave function after visiting that node and all of its child nodes.
 *
 * By returning different values from the `enter` and `leave` functions, the behavior of the visitor can be altered.
 *
 * - no return (`void`) or return `null`: no action
 * - `Visitor::skipNode()`: skips over the subtree at the current node of the AST
 * - `Visitor::stop()`: stop the Visitor completely
 * - `Visitor::removeNode()`: remove the current node
 * - return any other value: replace this node with the returned value
 *
 * When using `visit()` to edit an AST, the original AST will not be modified, and
 * a new version of the AST with the changes applied will be returned from the
 * visit function.
 *
 * ```php
 * $editedAST = Visitor::visit($ast, [
 *     'enter' => function (Node $node, $key, $parent, array $path, array $ancestors) {
 *         // ...
 *     },
 *     'leave' => function (Node $node, $key, $parent, array $path, array $ancestors) {
 *         // ...
 *     }
 * ]);
 * ```
 *
 * Alternatively to providing `enter` and `leave` functions, a visitor can
 * instead provide functions named the same as the [kinds of AST nodes](class-reference.md#graphqllanguageastnodekind),
 * or enter/leave visitors at a named key, leading to four permutations of
 * visitor API:
 *
 * 1. Named visitors triggered when entering a node a specific kind.
 *
 *    ```php
 *    Visitor::visit($ast, [
 *        NodeKind::OBJECT_TYPE_DEFINITION => function (ObjectTypeDefinitionNode $node) {
 *            // enter the "ObjectTypeDefinition" node
 *        }
 *    ]);
 *    ```
 *
 * 2. Named visitors that trigger upon entering and leaving a node of
 *    a specific kind.
 *
 *    ```php
 *    Visitor::visit($ast, [
 *        NodeKind::OBJECT_TYPE_DEFINITION => [
 *            'enter' => function (ObjectTypeDefinitionNode $node) {
 *                // enter the "ObjectTypeDefinition" node
 *            },
 *            'leave' => function (ObjectTypeDefinitionNode $node) {
 *                // leave the "ObjectTypeDefinition" node
 *            }
 *        ]
 *    ]);
 *    ```
 *
 * 3. Generic visitors that trigger upon entering and leaving any node.
 *
 *    ```php
 *    Visitor::visit($ast, [
 *        'enter' => function (Node $node) {
 *            // enter any node
 *        },
 *        'leave' => function (Node $node) {
 *            // leave any node
 *        }
 *    ]);
 *    ```
 *
 * 4. Parallel visitors for entering and leaving nodes of a specific kind.
 *
 *    ```php
 *    Visitor::visit($ast, [
 *        'enter' => [
 *            NodeKind::OBJECT_TYPE_DEFINITION => function (ObjectTypeDefinitionNode $node) {
 *                // enter the "ObjectTypeDefinition" node
 *            }
 *        ],
 *        'leave' => [
 *            NodeKind::OBJECT_TYPE_DEFINITION => function (ObjectTypeDefinitionNode $node) {
 *                // leave the "ObjectTypeDefinition" node
 *            }
 *        ]
 *    ]);
 *    ```
 *
 * @phpstan-type NodeVisitor callable(Node): (VisitorOperation|Node|NodeList<Node>|null|false|void)
 * @phpstan-type VisitorArray array<string, NodeVisitor>|array<string, array<string, NodeVisitor>>
 *
 * @see \GraphQL\Tests\Language\VisitorTest
 */
class Visitor
{
    public const VISITOR_KEYS = [Node_Kind::NAME => [], Node_Kind::DOCUMENT => ['definitions'], Node_Kind::OPERATION_DEFINITION => ['name', 'variableDefinitions', 'directives', 'selectionSet'], Node_Kind::VARIABLE_DEFINITION => ['variable', 'type', 'defaultValue', 'directives'], Node_Kind::VARIABLE => ['name'], Node_Kind::SELECTION_SET => ['selections'], Node_Kind::FIELD => ['alias', 'name', 'arguments', 'directives', 'selectionSet'], Node_Kind::ARGUMENT => ['name', 'value'], Node_Kind::FRAGMENT_SPREAD => ['name', 'directives'], Node_Kind::INLINE_FRAGMENT => ['typeCondition', 'directives', 'selectionSet'], Node_Kind::FRAGMENT_DEFINITION => [
        'name',
        // Note: fragment variable definitions are experimental and may be changed
        // or removed in the future.
        'variableDefinitions',
        'typeCondition',
        'directives',
        'selectionSet',
    ], Node_Kind::INT => [], Node_Kind::FLOAT => [], Node_Kind::STRING => [], Node_Kind::BOOLEAN => [], Node_Kind::NULL => [], Node_Kind::ENUM => [], Node_Kind::LST => ['values'], Node_Kind::OBJECT => ['fields'], Node_Kind::OBJECT_FIELD => ['name', 'value'], Node_Kind::DIRECTIVE => ['name', 'arguments'], Node_Kind::NAMED_TYPE => ['name'], Node_Kind::LIST_TYPE => ['type'], Node_Kind::NON_NULL_TYPE => ['type'], Node_Kind::SCHEMA_DEFINITION => ['description', 'directives', 'operationTypes'], Node_Kind::OPERATION_TYPE_DEFINITION => ['type'], Node_Kind::SCALAR_TYPE_DEFINITION => ['description', 'name', 'directives'], Node_Kind::OBJECT_TYPE_DEFINITION => ['description', 'name', 'interfaces', 'directives', 'fields'], Node_Kind::FIELD_DEFINITION => ['description', 'name', 'arguments', 'type', 'directives'], Node_Kind::INPUT_VALUE_DEFINITION => ['description', 'name', 'type', 'defaultValue', 'directives'], Node_Kind::INTERFACE_TYPE_DEFINITION => ['description', 'name', 'interfaces', 'directives', 'fields'], Node_Kind::UNION_TYPE_DEFINITION => ['description', 'name', 'directives', 'types'], Node_Kind::ENUM_TYPE_DEFINITION => ['description', 'name', 'directives', 'values'], Node_Kind::ENUM_VALUE_DEFINITION => ['description', 'name', 'directives'], Node_Kind::INPUT_OBJECT_TYPE_DEFINITION => ['description', 'name', 'directives', 'fields'], Node_Kind::SCALAR_TYPE_EXTENSION => ['name', 'directives'], Node_Kind::OBJECT_TYPE_EXTENSION => ['name', 'interfaces', 'directives', 'fields'], Node_Kind::INTERFACE_TYPE_EXTENSION => ['name', 'interfaces', 'directives', 'fields'], Node_Kind::UNION_TYPE_EXTENSION => ['name', 'directives', 'types'], Node_Kind::ENUM_TYPE_EXTENSION => ['name', 'directives', 'values'], Node_Kind::INPUT_OBJECT_TYPE_EXTENSION => ['name', 'directives', 'fields'], Node_Kind::DIRECTIVE_DEFINITION => ['description', 'name', 'arguments', 'locations'], Node_Kind::SCHEMA_EXTENSION => ['directives', 'operationTypes']];
    /**
     * Visit the AST (see class description for details).
     *
     * @param NodeList<Node>|Node $root
     * @param VisitorArray $visitor
     * @param array<string, mixed>|null $keyMap
     *
     * @throws \Exception
     *
     * @return mixed
     *
     * @api
     */
    public static function visit(object $root, array $visitor, ?array $key_map = null)
    {
        $visitor_keys = $key_map ?? self::VISITOR_KEYS;
        /**
         * @var list<array{
         *   inList: bool,
         *   index: int,
         *   keys: Node|NodeList|mixed,
         *   edits: array<int, array{mixed, mixed}>,
         * }> $stack */
        $stack = [];
        $in_list = $root instanceof Node_List;
        $keys = [$root];
        $index = -1;
        $edits = [];
        $parent = null;
        $path = [];
        $ancestors = [];
        do {
            ++$index;
            $is_leaving = $index === count($keys);
            $key = null;
            $node = null;
            $is_edited = $is_leaving && $edits !== [];
            if ($is_leaving) {
                $key = $ancestors === [] ? null : $path[count($path) - 1];
                $node = $parent;
                $parent = array_pop($ancestors);
                if ($is_edited) {
                    if ($node instanceof Node || $node instanceof Node_List) {
                        $node = $node->clone_deep();
                    }
                    $edit_offset = 0;
                    foreach ($edits as [$edit_key, $edit_value]) {
                        if ($in_list) {
                            $edit_key -= $edit_offset;
                        }
                        if ($in_list && $edit_value === null) {
                            assert($node instanceof Node_List, 'Follows from $inList');
                            $node->splice($edit_key, 1);
                            ++$edit_offset;
                        } elseif ($node instanceof Node_List) {
                            if ($edit_value instanceof Node_List) {
                                $node->splice($edit_key, 1, $edit_value);
                                $edit_offset -= count($edit_value) - 1;
                            } elseif ($edit_value instanceof Node) {
                                $node[$edit_key] = $edit_value;
                            } else {
                                $not_node_or_node_list = Utils::print_safe($edit_value);
                                throw new \Exception("Can only add Node or NodeList to NodeList, got: {$not_node_or_node_list}.");
                            }
                        } else {
                            $node->{$edit_key} = $edit_value;
                        }
                    }
                }
                // @phpstan-ignore-next-line the stack is guaranteed to be non-empty at this point
                ['index' => $index, 'keys' => $keys, 'edits' => $edits, 'inList' => $in_list] = array_pop($stack);
            } elseif ($parent === null) {
                $node = $root;
            } else {
                $key = $in_list ? $index : $keys[$index];
                $node = $parent instanceof Node_List ? $parent[$key] : $parent->{$key};
                if ($node === null) {
                    continue;
                }
                $path[] = $key;
            }
            $result = null;
            if (!$node instanceof Node_List) {
                if (!$node instanceof Node) {
                    $not_node = Utils::print_safe($node);
                    throw new \Exception("Invalid AST Node: {$not_node}.");
                }
                $visit_fn = self::extract_visit_fn($visitor, $node->kind, $is_leaving);
                if ($visit_fn !== null) {
                    $result = $visit_fn($node, $key, $parent, $path, $ancestors);
                    if ($result !== null) {
                        if ($result instanceof Visitor_Stop) {
                            break;
                        }
                        if ($result instanceof Visitor_Skip_Node) {
                            if (!$is_leaving) {
                                array_pop($path);
                            }
                            continue;
                        }
                        $edit_value = $result instanceof Visitor_Remove_Node ? null : $result;
                        $edits[] = [$key, $edit_value];
                        if (!$is_leaving) {
                            if (!$edit_value instanceof Node) {
                                array_pop($path);
                                continue;
                            }
                            $node = $edit_value;
                        }
                    }
                }
            }
            if ($result === null && $is_edited) {
                $edits[] = [$key, $node];
            }
            if ($is_leaving) {
                array_pop($path);
            } else {
                $stack[] = ['inList' => $in_list, 'index' => $index, 'keys' => $keys, 'edits' => $edits];
                $in_list = $node instanceof Node_List;
                $keys = ($in_list ? $node : $visitor_keys[$node->kind]) ?? [];
                $index = -1;
                $edits = [];
                if ($parent !== null) {
                    $ancestors[] = $parent;
                }
                $parent = $node;
            }
        } while ($stack !== []);
        return $edits === [] ? $root : $edits[0][1];
    }
    /**
     * Returns marker for stopping.
     *
     * @api
     */
    public static function stop(): Visitor_Stop
    {
        static $stop;
        return $stop ??= new Visitor_Stop();
    }
    /**
     * Returns marker for skipping the subtree at the current node.
     *
     * @api
     */
    public static function skip_node(): Visitor_Skip_Node
    {
        static $skip_node;
        return $skip_node ??= new Visitor_Skip_Node();
    }
    /**
     * Returns marker for removing the current node.
     *
     * @api
     */
    public static function remove_node(): Visitor_Remove_Node
    {
        static $remove_node;
        return $remove_node ??= new Visitor_Remove_Node();
    }
    /**
     * Combines the given visitors to run in parallel.
     *
     * @phpstan-param array<int, VisitorArray> $visitors
     *
     * @return VisitorArray
     */
    public static function visit_in_parallel(array $visitors): array
    {
        $visitors_count = count($visitors);
        $skipping = new \SplFixedArray($visitors_count);
        return ['enter' => static function (Node $node) use ($visitors, $skipping, $visitors_count) {
            for ($i = 0; $i < $visitors_count; ++$i) {
                if ($skipping[$i] !== null) {
                    continue;
                }
                $fn = self::extract_visit_fn($visitors[$i], $node->kind, false);
                if ($fn === null) {
                    continue;
                }
                $result = $fn(...func_get_args());
                if ($result === null) {
                    continue;
                }
                if ($result instanceof Visitor_Skip_Node) {
                    $skipping[$i] = $node;
                } elseif ($result instanceof Visitor_Stop) {
                    $skipping[$i] = $result;
                } else {
                    return $result;
                }
            }
            return null;
        }, 'leave' => static function (Node $node) use ($visitors, $skipping, $visitors_count) {
            for ($i = 0; $i < $visitors_count; ++$i) {
                if ($skipping[$i] === null) {
                    $fn = self::extract_visit_fn($visitors[$i], $node->kind, true);
                    if ($fn !== null) {
                        $result = $fn(...func_get_args());
                        if ($result === null) {
                            continue;
                        }
                        if ($result instanceof Visitor_Stop) {
                            $skipping[$i] = $result;
                        } elseif ($result instanceof Visitor_Remove_Node) {
                            return $result;
                        } else {
                            return $result;
                        }
                    }
                } elseif ($skipping[$i] === $node) {
                    $skipping[$i] = null;
                }
            }
            return null;
        }];
    }
    /**
     * Creates a new visitor that updates TypeInfo and delegates to the given visitor.
     *
     * @phpstan-param VisitorArray $visitor
     *
     * @phpstan-return VisitorArray
     */
    public static function visit_with_type_info(Type_Info $type_info, array $visitor): array
    {
        return ['enter' => static function (Node $node) use ($type_info, $visitor) {
            $type_info->enter($node);
            $fn = self::extract_visit_fn($visitor, $node->kind, false);
            if ($fn === null) {
                return null;
            }
            $result = $fn(...func_get_args());
            if ($result === null) {
                return null;
            }
            $type_info->leave($node);
            if ($result instanceof Node) {
                $type_info->enter($result);
            }
            return $result;
        }, 'leave' => static function (Node $node) use ($type_info, $visitor) {
            $fn = self::extract_visit_fn($visitor, $node->kind, true);
            $result = $fn !== null ? $fn(...func_get_args()) : null;
            $type_info->leave($node);
            return $result;
        }];
    }
    /**
     * @phpstan-param VisitorArray $visitor
     *
     * @return (callable(Node $node, string|int|null $key, Node|NodeList<Node>|null $parent, array<int, int|string> $path, array<int, Node|NodeList<Node>> $ancestors): (VisitorOperation|Node|null))|(callable(Node): (VisitorOperation|Node|NodeList<Node>|void|false|null))|null
     */
    protected static function extract_visit_fn(array $visitor, string $kind, bool $is_leaving): ?callable
    {
        $kind_visitor = $visitor[$kind] ?? null;
        if ($kind_visitor !== null) {
            if (is_array($kind_visitor)) {
                return $is_leaving ? $kind_visitor['leave'] ?? null : $kind_visitor['enter'] ?? null;
            }
            if (!$is_leaving) {
                return $kind_visitor;
            }
        }
        $specific_visitor = $is_leaving ? $visitor['leave'] ?? null : $visitor['enter'] ?? null;
        if ($specific_visitor !== null && is_array($specific_visitor)) {
            return $specific_visitor[$kind] ?? null;
        }
        return $specific_visitor;
    }
}