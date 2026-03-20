<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

/**
 * Holds constants of possible AST nodes.
 */
class Node_Kind
{
    // constants from language/kinds.js:
    public const NAME = 'Name';
    // Document
    public const DOCUMENT = 'Document';
    public const OPERATION_DEFINITION = 'OperationDefinition';
    public const VARIABLE_DEFINITION = 'VariableDefinition';
    public const VARIABLE = 'Variable';
    public const SELECTION_SET = 'SelectionSet';
    public const FIELD = 'Field';
    public const ARGUMENT = 'Argument';
    // Fragments
    public const FRAGMENT_SPREAD = 'FragmentSpread';
    public const INLINE_FRAGMENT = 'InlineFragment';
    public const FRAGMENT_DEFINITION = 'FragmentDefinition';
    // Values
    public const INT = 'IntValue';
    public const FLOAT = 'FloatValue';
    public const STRING = 'StringValue';
    public const BOOLEAN = 'BooleanValue';
    public const ENUM = 'EnumValue';
    public const NULL = 'NullValue';
    public const LST = 'ListValue';
    public const OBJECT = 'ObjectValue';
    public const OBJECT_FIELD = 'ObjectField';
    // Directives
    public const DIRECTIVE = 'Directive';
    // Types
    public const NAMED_TYPE = 'NamedType';
    public const LIST_TYPE = 'ListType';
    public const NON_NULL_TYPE = 'NonNullType';
    // Type System Definitions
    public const SCHEMA_DEFINITION = 'SchemaDefinition';
    public const OPERATION_TYPE_DEFINITION = 'OperationTypeDefinition';
    // Type Definitions
    public const SCALAR_TYPE_DEFINITION = 'ScalarTypeDefinition';
    public const OBJECT_TYPE_DEFINITION = 'ObjectTypeDefinition';
    public const FIELD_DEFINITION = 'FieldDefinition';
    public const INPUT_VALUE_DEFINITION = 'InputValueDefinition';
    public const INTERFACE_TYPE_DEFINITION = 'InterfaceTypeDefinition';
    public const UNION_TYPE_DEFINITION = 'UnionTypeDefinition';
    public const ENUM_TYPE_DEFINITION = 'EnumTypeDefinition';
    public const ENUM_VALUE_DEFINITION = 'EnumValueDefinition';
    public const INPUT_OBJECT_TYPE_DEFINITION = 'InputObjectTypeDefinition';
    // Type Extensions
    public const SCALAR_TYPE_EXTENSION = 'ScalarTypeExtension';
    public const OBJECT_TYPE_EXTENSION = 'ObjectTypeExtension';
    public const INTERFACE_TYPE_EXTENSION = 'InterfaceTypeExtension';
    public const UNION_TYPE_EXTENSION = 'UnionTypeExtension';
    public const ENUM_TYPE_EXTENSION = 'EnumTypeExtension';
    public const INPUT_OBJECT_TYPE_EXTENSION = 'InputObjectTypeExtension';
    // Directive Definitions
    public const DIRECTIVE_DEFINITION = 'DirectiveDefinition';
    // Type System Extensions
    public const SCHEMA_EXTENSION = 'SchemaExtension';
    public const CLASS_MAP = [
        self::NAME => Name_Node::class,
        // Document
        self::DOCUMENT => Document_Node::class,
        self::OPERATION_DEFINITION => Operation_Definition_Node::class,
        self::VARIABLE_DEFINITION => Variable_Definition_Node::class,
        self::VARIABLE => Variable_Node::class,
        self::SELECTION_SET => Selection_Set_Node::class,
        self::FIELD => Field_Node::class,
        self::ARGUMENT => Argument_Node::class,
        // Fragments
        self::FRAGMENT_SPREAD => Fragment_Spread_Node::class,
        self::INLINE_FRAGMENT => Inline_Fragment_Node::class,
        self::FRAGMENT_DEFINITION => Fragment_Definition_Node::class,
        // Values
        self::INT => Int_Value_Node::class,
        self::FLOAT => Float_Value_Node::class,
        self::STRING => String_Value_Node::class,
        self::BOOLEAN => Boolean_Value_Node::class,
        self::ENUM => Enum_Value_Node::class,
        self::NULL => Null_Value_Node::class,
        self::LST => List_Value_Node::class,
        self::OBJECT => Object_Value_Node::class,
        self::OBJECT_FIELD => Object_Field_Node::class,
        // Directives
        self::DIRECTIVE => Directive_Node::class,
        // Types
        self::NAMED_TYPE => Named_Type_Node::class,
        self::LIST_TYPE => List_Type_Node::class,
        self::NON_NULL_TYPE => Non_Null_Type_Node::class,
        // Type System Definitions
        self::SCHEMA_DEFINITION => Schema_Definition_Node::class,
        self::OPERATION_TYPE_DEFINITION => Operation_Type_Definition_Node::class,
        // Type Definitions
        self::SCALAR_TYPE_DEFINITION => Scalar_Type_Definition_Node::class,
        self::OBJECT_TYPE_DEFINITION => Object_Type_Definition_Node::class,
        self::FIELD_DEFINITION => Field_Definition_Node::class,
        self::INPUT_VALUE_DEFINITION => Input_Value_Definition_Node::class,
        self::INTERFACE_TYPE_DEFINITION => Interface_Type_Definition_Node::class,
        self::UNION_TYPE_DEFINITION => Union_Type_Definition_Node::class,
        self::ENUM_TYPE_DEFINITION => Enum_Type_Definition_Node::class,
        self::ENUM_VALUE_DEFINITION => Enum_Value_Definition_Node::class,
        self::INPUT_OBJECT_TYPE_DEFINITION => Input_Object_Type_Definition_Node::class,
        // Type Extensions
        self::SCALAR_TYPE_EXTENSION => Scalar_Type_Extension_Node::class,
        self::OBJECT_TYPE_EXTENSION => Object_Type_Extension_Node::class,
        self::INTERFACE_TYPE_EXTENSION => Interface_Type_Extension_Node::class,
        self::UNION_TYPE_EXTENSION => Union_Type_Extension_Node::class,
        self::ENUM_TYPE_EXTENSION => Enum_Type_Extension_Node::class,
        self::INPUT_OBJECT_TYPE_EXTENSION => Input_Object_Type_Extension_Node::class,
        // Directive Definitions
        self::DIRECTIVE_DEFINITION => Directive_Definition_Node::class,
    ];
}