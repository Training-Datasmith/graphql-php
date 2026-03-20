<?php

declare (strict_types=1);
namespace Graph_Ql\Type;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Graph_Ql;
use Graph_Ql\Language\Directive_Location;
use Graph_Ql\Language\Printer;
use Graph_Ql\Type\Definition\Argument;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Enum_Type;
use Graph_Ql\Type\Definition\Enum_Value_Definition;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Definition\Input_Object_Field;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\List_Of_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Resolve_Info;
use Graph_Ql\Type\Definition\Scalar_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Definition\Union_Type;
use Graph_Ql\Type\Definition\Wrapping_Type;
use Graph_Ql\Utils\AST;
use Graph_Ql\Utils\Utils;
/**
 * @phpstan-type IntrospectionOptions array{
 *     descriptions?: bool,
 *     directiveIsRepeatable?: bool,
 *     schemaDescription?: bool,
 *     typeIsOneOf?: bool,
 * }
 *
 * Available options:
 * - descriptions
 *   Include descriptions in the introspection result?
 *   Default: true
 * - directiveIsRepeatable
 *   Include field `isRepeatable` for directives?
 *   Default: false
 * - typeIsOneOf
 *   Include field `isOneOf` for types?
 *   Default: false
 *
 * @see \GraphQL\Tests\Type\IntrospectionTest
 */
class Introspection
{
    public const SCHEMA_FIELD_NAME = '__schema';
    public const TYPE_FIELD_NAME = '__type';
    public const TYPE_NAME_FIELD_NAME = '__typename';
    public const SCHEMA_OBJECT_NAME = '__Schema';
    public const TYPE_OBJECT_NAME = '__Type';
    public const DIRECTIVE_OBJECT_NAME = '__Directive';
    public const FIELD_OBJECT_NAME = '__Field';
    public const INPUT_VALUE_OBJECT_NAME = '__InputValue';
    public const ENUM_VALUE_OBJECT_NAME = '__EnumValue';
    public const TYPE_KIND_ENUM_NAME = '__TypeKind';
    public const DIRECTIVE_LOCATION_ENUM_NAME = '__DirectiveLocation';
    public const TYPE_NAMES = [self::SCHEMA_OBJECT_NAME, self::TYPE_OBJECT_NAME, self::DIRECTIVE_OBJECT_NAME, self::FIELD_OBJECT_NAME, self::INPUT_VALUE_OBJECT_NAME, self::ENUM_VALUE_OBJECT_NAME, self::TYPE_KIND_ENUM_NAME, self::DIRECTIVE_LOCATION_ENUM_NAME];
    /** @var array<string, mixed>|null */
    protected static ?array $cached_instances;
    /**
     * @param IntrospectionOptions $options
     *
     * @api
     */
    public static function get_introspection_query(array $options = []): string
    {
        $options_with_defaults = array_merge(['descriptions' => true, 'directiveIsRepeatable' => false, 'schemaDescription' => false, 'typeIsOneOf' => false], $options);
        $descriptions = $options_with_defaults['descriptions'] ? 'description' : '';
        $directive_is_repeatable = $options_with_defaults['directiveIsRepeatable'] ? 'isRepeatable' : '';
        $schema_description = $options_with_defaults['schemaDescription'] ? $descriptions : '';
        $type_is_one_of = $options_with_defaults['typeIsOneOf'] ? 'isOneOf' : '';
        return <<<GRAPHQL
          query IntrospectionQuery {
            __schema {
              {$schema_description}
              queryType { name }
              mutationType { name }
              subscriptionType { name }
              types {
                ...FullType
              }
              directives {
                name
                {$descriptions}
                args(includeDeprecated: true) {
                  ...InputValue
                }
                {$directive_is_repeatable}
                locations
              }
            }
          }
        
          fragment FullType on __Type {
            kind
            name
            {$descriptions}
            {$type_is_one_of}
            fields(includeDeprecated: true) {
              name
              {$descriptions}
              args(includeDeprecated: true) {
                ...InputValue
              }
              type {
                ...TypeRef
              }
              isDeprecated
              deprecationReason
            }
            inputFields(includeDeprecated: true) {
              ...InputValue
            }
            interfaces {
              ...TypeRef
            }
            enumValues(includeDeprecated: true) {
              name
              {$descriptions}
              isDeprecated
              deprecationReason
            }
            possibleTypes {
              ...TypeRef
            }
          }
        
          fragment InputValue on __InputValue {
            name
            {$descriptions}
            type { ...TypeRef }
            defaultValue
            isDeprecated
            deprecationReason
          }
        
          fragment TypeRef on __Type {
            kind
            name
            ofType {
              kind
              name
              ofType {
                kind
                name
                ofType {
                  kind
                  name
                  ofType {
                    kind
                    name
                    ofType {
                      kind
                      name
                      ofType {
                        kind
                        name
                        ofType {
                          kind
                          name
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        GRAPHQL;
    }
    /**
     * Build an introspection query from a Schema.
     *
     * Introspection is useful for utilities that care about type and field
     * relationships, but do not need to traverse through those relationships.
     *
     * This is the inverse of BuildClientSchema::build(). The primary use case is
     * outside the server context, for instance when doing schema comparisons.
     *
     * @param IntrospectionOptions $options
     *
     * @throws \Exception
     * @throws \JsonException
     * @throws InvariantViolation
     *
     * @return array<string, array<mixed>>
     *
     * @api
     */
    public static function from_schema(Schema $schema, array $options = []): array
    {
        $options_with_defaults = array_merge(['directiveIsRepeatable' => true, 'schemaDescription' => true, 'typeIsOneOf' => true], $options);
        $result = Graph_Ql::execute_query($schema, self::get_introspection_query($options_with_defaults));
        $data = $result->data;
        if ($data === null) {
            $no_data_result = Utils::print_safe_json($result);
            throw new Invariant_Violation("Introspection query returned no data: {$no_data_result}.");
        }
        return $data;
    }
    /** @param Type&NamedType $type */
    public static function is_introspection_type(Named_Type $type): bool
    {
        return in_array($type->name, self::TYPE_NAMES, true);
    }
    /** @return array<string, Type&NamedType> */
    public static function get_types(): array
    {
        return [self::SCHEMA_OBJECT_NAME => self::_schema(), self::TYPE_OBJECT_NAME => self::_type(), self::DIRECTIVE_OBJECT_NAME => self::_directive(), self::FIELD_OBJECT_NAME => self::_field(), self::INPUT_VALUE_OBJECT_NAME => self::_input_value(), self::ENUM_VALUE_OBJECT_NAME => self::_enum_value(), self::TYPE_KIND_ENUM_NAME => self::_type_kind(), self::DIRECTIVE_LOCATION_ENUM_NAME => self::_directive_location()];
    }
    public static function _schema(): Object_Type
    {
        return self::$cached_instances[self::SCHEMA_OBJECT_NAME] ??= new Object_Type([
            // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
            'name' => self::SCHEMA_OBJECT_NAME,
            'isIntrospection' => true,
            'description' => 'A GraphQL Schema defines the capabilities of a GraphQL ' . 'server. It exposes all available types and directives on ' . 'the server, as well as the entry points for query, mutation, and ' . 'subscription operations.',
            'fields' => ['description' => ['type' => Type::string(), 'resolve' => static fn(Schema $schema): ?string => $schema->description], 'types' => ['description' => 'A list of all types supported by this server.', 'type' => new Non_Null(new List_Of_Type(new Non_Null(self::_type()))), 'resolve' => static fn(Schema $schema): array => $schema->get_type_map()], 'queryType' => ['description' => 'The type that query operations will be rooted at.', 'type' => new Non_Null(self::_type()), 'resolve' => static fn(Schema $schema): ?Object_Type => $schema->get_query_type()], 'mutationType' => ['description' => 'If this server supports mutation, the type that mutation operations will be rooted at.', 'type' => self::_type(), 'resolve' => static fn(Schema $schema): ?Object_Type => $schema->get_mutation_type()], 'subscriptionType' => ['description' => 'If this server support subscription, the type that subscription operations will be rooted at.', 'type' => self::_type(), 'resolve' => static fn(Schema $schema): ?Object_Type => $schema->get_subscription_type()], 'directives' => ['description' => 'A list of all directives supported by this server.', 'type' => Type::non_null(Type::list_of(Type::non_null(self::_directive()))), 'resolve' => static fn(Schema $schema): array => $schema->get_directives()]],
        ]);
    }
    public static function _type(): Object_Type
    {
        return self::$cached_instances[self::TYPE_OBJECT_NAME] ??= new Object_Type([
            // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
            'name' => self::TYPE_OBJECT_NAME,
            'isIntrospection' => true,
            'description' => 'The fundamental unit of any GraphQL Schema is the type. There are ' . 'many kinds of types in GraphQL as represented by the `__TypeKind` enum.' . "\n\n" . 'Depending on the kind of a type, certain fields describe ' . 'information about that type. Scalar types provide no information ' . 'beyond a name and description, while Enum types provide their values. ' . 'Object and Interface types provide the fields they describe. Abstract ' . 'types, Union and Interface, provide the Object types possible ' . 'at runtime. List and NonNull types compose other types.',
            'fields' => static fn(): array => ['kind' => ['type' => Type::non_null(self::_type_kind()), 'resolve' => static function (Type $type): string {
                switch (true) {
                    case $type instanceof List_Of_Type:
                        return Type_Kind::LIST;
                    case $type instanceof Non_Null:
                        return Type_Kind::NON_NULL;
                    case $type instanceof Scalar_Type:
                        return Type_Kind::SCALAR;
                    case $type instanceof Object_Type:
                        return Type_Kind::OBJECT;
                    case $type instanceof Enum_Type:
                        return Type_Kind::ENUM;
                    case $type instanceof Input_Object_Type:
                        return Type_Kind::INPUT_OBJECT;
                    case $type instanceof Interface_Type:
                        return Type_Kind::INTERFACE;
                    case $type instanceof Union_Type:
                        return Type_Kind::UNION;
                    default:
                        $safe_type = Utils::print_safe($type);
                        throw new \Exception("Unknown kind of type: {$safe_type}");
                }
            }], 'name' => ['type' => Type::string(), 'resolve' => static fn(Type $type): ?string => $type instanceof Named_Type ? $type->name : null], 'description' => ['type' => Type::string(), 'resolve' => static fn(Type $type): ?string => $type instanceof Named_Type ? $type->description : null], 'fields' => ['type' => Type::list_of(Type::non_null(self::_field())), 'args' => ['includeDeprecated' => ['type' => Type::non_null(Type::boolean()), 'defaultValue' => false]], 'resolve' => static function (Type $type, array $args): ?array {
                if ($type instanceof Object_Type || $type instanceof Interface_Type) {
                    $fields = $type->get_visible_fields();
                    if (!$args['includeDeprecated']) {
                        return array_filter($fields, static fn(Field_Definition $field): bool => !$field->is_deprecated());
                    }
                    return $fields;
                }
                return null;
            }], 'interfaces' => ['type' => Type::list_of(Type::non_null(self::_type())), 'resolve' => static fn($type): ?array => $type instanceof Object_Type || $type instanceof Interface_Type ? $type->get_interfaces() : null], 'possibleTypes' => ['type' => Type::list_of(Type::non_null(self::_type())), 'resolve' => static fn($type, $args, $context, Resolve_Info $info): ?array => $type instanceof Interface_Type || $type instanceof Union_Type ? $info->schema->get_possible_types($type) : null], 'enumValues' => ['type' => Type::list_of(Type::non_null(self::_enum_value())), 'args' => ['includeDeprecated' => ['type' => Type::non_null(Type::boolean()), 'defaultValue' => false]], 'resolve' => static function ($type, array $args): ?array {
                if ($type instanceof Enum_Type) {
                    $values = $type->get_values();
                    if (!$args['includeDeprecated']) {
                        return array_filter($values, static fn(Enum_Value_Definition $value): bool => !$value->is_deprecated());
                    }
                    return $values;
                }
                return null;
            }], 'inputFields' => ['type' => Type::list_of(Type::non_null(self::_input_value())), 'args' => ['includeDeprecated' => ['type' => Type::non_null(Type::boolean()), 'defaultValue' => false]], 'resolve' => static function ($type, array $args): ?array {
                if ($type instanceof Input_Object_Type) {
                    $fields = $type->get_fields();
                    if (!$args['includeDeprecated']) {
                        return array_filter($fields, static fn(Input_Object_Field $field): bool => !$field->is_deprecated());
                    }
                    return $fields;
                }
                return null;
            }], 'ofType' => ['type' => self::_type(), 'resolve' => static fn($type): ?Type => $type instanceof Wrapping_Type ? $type->get_wrapped_type() : null], 'isOneOf' => ['type' => Type::boolean(), 'resolve' => static fn($type): ?bool => $type instanceof Input_Object_Type ? $type->is_one_of() : null]],
        ]);
    }
    public static function _type_kind(): Enum_Type
    {
        return self::$cached_instances[self::TYPE_KIND_ENUM_NAME] ??= new Enum_Type([
            // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
            'name' => self::TYPE_KIND_ENUM_NAME,
            'isIntrospection' => true,
            'description' => 'An enum describing what kind of type a given `__Type` is.',
            'values' => ['SCALAR' => ['value' => Type_Kind::SCALAR, 'description' => 'Indicates this type is a scalar.'], 'OBJECT' => ['value' => Type_Kind::OBJECT, 'description' => 'Indicates this type is an object. `fields` and `interfaces` are valid fields.'], 'INTERFACE' => ['value' => Type_Kind::INTERFACE, 'description' => 'Indicates this type is an interface. `fields`, `interfaces`, and `possibleTypes` are valid fields.'], 'UNION' => ['value' => Type_Kind::UNION, 'description' => 'Indicates this type is a union. `possibleTypes` is a valid field.'], 'ENUM' => ['value' => Type_Kind::ENUM, 'description' => 'Indicates this type is an enum. `enumValues` is a valid field.'], 'INPUT_OBJECT' => ['value' => Type_Kind::INPUT_OBJECT, 'description' => 'Indicates this type is an input object. `inputFields` is a valid field.'], 'LIST' => ['value' => Type_Kind::LIST, 'description' => 'Indicates this type is a list. `ofType` is a valid field.'], 'NON_NULL' => ['value' => Type_Kind::NON_NULL, 'description' => 'Indicates this type is a non-null. `ofType` is a valid field.']],
        ]);
    }
    public static function _field(): Object_Type
    {
        return self::$cached_instances[self::FIELD_OBJECT_NAME] ??= new Object_Type([
            // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
            'name' => self::FIELD_OBJECT_NAME,
            'isIntrospection' => true,
            'description' => 'Object and Interface types are described by a list of Fields, each of ' . 'which has a name, potentially a list of arguments, and a return type.',
            'fields' => static fn(): array => ['name' => ['type' => Type::non_null(Type::string()), 'resolve' => static fn(Field_Definition $field): string => $field->name], 'description' => ['type' => Type::string(), 'resolve' => static fn(Field_Definition $field): ?string => $field->description], 'args' => ['type' => Type::non_null(Type::list_of(Type::non_null(self::_input_value()))), 'args' => ['includeDeprecated' => ['type' => Type::non_null(Type::boolean()), 'defaultValue' => false]], 'resolve' => static function (Field_Definition $field, array $args): array {
                $values = $field->args;
                if (!$args['includeDeprecated']) {
                    return array_filter($values, static fn(Argument $value): bool => !$value->is_deprecated());
                }
                return $values;
            }], 'type' => ['type' => Type::non_null(self::_type()), 'resolve' => static fn(Field_Definition $field): Type => $field->get_type()], 'isDeprecated' => ['type' => Type::non_null(Type::boolean()), 'resolve' => static fn(Field_Definition $field): bool => $field->is_deprecated()], 'deprecationReason' => ['type' => Type::string(), 'resolve' => static fn(Field_Definition $field): ?string => $field->deprecation_reason]],
        ]);
    }
    public static function _input_value(): Object_Type
    {
        return self::$cached_instances[self::INPUT_VALUE_OBJECT_NAME] ??= new Object_Type([
            // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
            'name' => self::INPUT_VALUE_OBJECT_NAME,
            'isIntrospection' => true,
            'description' => 'Arguments provided to Fields or Directives and the input fields of an ' . 'InputObject are represented as Input Values which describe their type ' . 'and optionally a default value.',
            'fields' => static fn(): array => ['name' => [
                'type' => Type::non_null(Type::string()),
                /** @param Argument|InputObjectField $inputValue */
                'resolve' => static fn($input_value): string => $input_value->name,
            ], 'description' => [
                'type' => Type::string(),
                /** @param Argument|InputObjectField $inputValue */
                'resolve' => static fn($input_value): ?string => $input_value->description,
            ], 'type' => [
                'type' => Type::non_null(self::_type()),
                /** @param Argument|InputObjectField $inputValue */
                'resolve' => static fn($input_value): Type => $input_value->get_type(),
            ], 'defaultValue' => [
                'type' => Type::string(),
                'description' => 'A GraphQL-formatted string representing the default value for this input value.',
                /** @param Argument|InputObjectField $inputValue */
                'resolve' => static function ($input_value): ?string {
                    if ($input_value->default_value_exists()) {
                        $default_value_ast = AST::ast_from_value($input_value->default_value, $input_value->get_type());
                        if ($default_value_ast === null) {
                            $inconvertible_default_value = Utils::print_safe($input_value->default_value);
                            throw new Invariant_Violation("Unable to convert defaultValue of argument {$input_value->name} into AST: {$inconvertible_default_value}.");
                        }
                        return Printer::do_print($default_value_ast);
                    }
                    return null;
                },
            ], 'isDeprecated' => [
                'type' => Type::non_null(Type::boolean()),
                /** @param Argument|InputObjectField $inputValue */
                'resolve' => static fn($input_value): bool => $input_value->is_deprecated(),
            ], 'deprecationReason' => [
                'type' => Type::string(),
                /** @param Argument|InputObjectField $inputValue */
                'resolve' => static fn($input_value): ?string => $input_value->deprecation_reason,
            ]],
        ]);
    }
    public static function _enum_value(): Object_Type
    {
        return self::$cached_instances[self::ENUM_VALUE_OBJECT_NAME] ??= new Object_Type([
            // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
            'name' => self::ENUM_VALUE_OBJECT_NAME,
            'isIntrospection' => true,
            'description' => 'One possible value for a given Enum. Enum values are unique values, not ' . 'a placeholder for a string or numeric value. However an Enum value is ' . 'returned in a JSON response as a string.',
            'fields' => ['name' => ['type' => Type::non_null(Type::string()), 'resolve' => static fn(Enum_Value_Definition $enum_value): string => $enum_value->name], 'description' => ['type' => Type::string(), 'resolve' => static fn(Enum_Value_Definition $enum_value): ?string => $enum_value->description], 'isDeprecated' => ['type' => Type::non_null(Type::boolean()), 'resolve' => static fn(Enum_Value_Definition $enum_value): bool => $enum_value->is_deprecated()], 'deprecationReason' => ['type' => Type::string(), 'resolve' => static fn(Enum_Value_Definition $enum_value): ?string => $enum_value->deprecation_reason]],
        ]);
    }
    public static function _directive(): Object_Type
    {
        return self::$cached_instances[self::DIRECTIVE_OBJECT_NAME] ??= new Object_Type([
            // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
            'name' => self::DIRECTIVE_OBJECT_NAME,
            'isIntrospection' => true,
            'description' => 'A Directive provides a way to describe alternate runtime execution and ' . 'type validation behavior in a GraphQL document.' . "\n\nIn some cases, you need to provide options to alter GraphQL's " . 'execution behavior in ways field arguments will not suffice, such as ' . 'conditionally including or skipping a field. Directives provide this by ' . 'describing additional information to the executor.',
            'fields' => ['name' => ['type' => Type::non_null(Type::string()), 'resolve' => static fn(Directive $directive): string => $directive->name], 'description' => ['type' => Type::string(), 'resolve' => static fn(Directive $directive): ?string => $directive->description], 'isRepeatable' => ['type' => Type::non_null(Type::boolean()), 'resolve' => static fn(Directive $directive): bool => $directive->is_repeatable], 'locations' => ['type' => Type::non_null(Type::list_of(Type::non_null(self::_directive_location()))), 'resolve' => static fn(Directive $directive): array => $directive->locations], 'args' => ['type' => Type::non_null(Type::list_of(Type::non_null(self::_input_value()))), 'args' => ['includeDeprecated' => ['type' => Type::non_null(Type::boolean()), 'defaultValue' => false]], 'resolve' => static function (Directive $directive, array $args): array {
                $values = $directive->args;
                if (!$args['includeDeprecated']) {
                    return array_filter($values, static fn(Argument $value): bool => !$value->is_deprecated());
                }
                return $values;
            }]],
        ]);
    }
    public static function _directive_location(): Enum_Type
    {
        return self::$cached_instances[self::DIRECTIVE_LOCATION_ENUM_NAME] ??= new Enum_Type([
            // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
            'name' => self::DIRECTIVE_LOCATION_ENUM_NAME,
            'isIntrospection' => true,
            'description' => 'A Directive can be adjacent to many parts of the GraphQL language, a ' . '__DirectiveLocation describes one such possible adjacencies.',
            'values' => ['QUERY' => ['value' => Directive_Location::QUERY, 'description' => 'Location adjacent to a query operation.'], 'MUTATION' => ['value' => Directive_Location::MUTATION, 'description' => 'Location adjacent to a mutation operation.'], 'SUBSCRIPTION' => ['value' => Directive_Location::SUBSCRIPTION, 'description' => 'Location adjacent to a subscription operation.'], 'FIELD' => ['value' => Directive_Location::FIELD, 'description' => 'Location adjacent to a field.'], 'FRAGMENT_DEFINITION' => ['value' => Directive_Location::FRAGMENT_DEFINITION, 'description' => 'Location adjacent to a fragment definition.'], 'FRAGMENT_SPREAD' => ['value' => Directive_Location::FRAGMENT_SPREAD, 'description' => 'Location adjacent to a fragment spread.'], 'INLINE_FRAGMENT' => ['value' => Directive_Location::INLINE_FRAGMENT, 'description' => 'Location adjacent to an inline fragment.'], 'VARIABLE_DEFINITION' => ['value' => Directive_Location::VARIABLE_DEFINITION, 'description' => 'Location adjacent to a variable definition.'], 'SCHEMA' => ['value' => Directive_Location::SCHEMA, 'description' => 'Location adjacent to a schema definition.'], 'SCALAR' => ['value' => Directive_Location::SCALAR, 'description' => 'Location adjacent to a scalar definition.'], 'OBJECT' => ['value' => Directive_Location::OBJECT, 'description' => 'Location adjacent to an object type definition.'], 'FIELD_DEFINITION' => ['value' => Directive_Location::FIELD_DEFINITION, 'description' => 'Location adjacent to a field definition.'], 'ARGUMENT_DEFINITION' => ['value' => Directive_Location::ARGUMENT_DEFINITION, 'description' => 'Location adjacent to an argument definition.'], 'INTERFACE' => ['value' => Directive_Location::IFACE, 'description' => 'Location adjacent to an interface definition.'], 'UNION' => ['value' => Directive_Location::UNION, 'description' => 'Location adjacent to a union definition.'], 'ENUM' => ['value' => Directive_Location::ENUM, 'description' => 'Location adjacent to an enum definition.'], 'ENUM_VALUE' => ['value' => Directive_Location::ENUM_VALUE, 'description' => 'Location adjacent to an enum value definition.'], 'INPUT_OBJECT' => ['value' => Directive_Location::INPUT_OBJECT, 'description' => 'Location adjacent to an input object type definition.'], 'INPUT_FIELD_DEFINITION' => ['value' => Directive_Location::INPUT_FIELD_DEFINITION, 'description' => 'Location adjacent to an input object field definition.']],
        ]);
    }
    public static function schema_meta_field_def(): Field_Definition
    {
        return self::$cached_instances[self::SCHEMA_FIELD_NAME] ??= new Field_Definition(['name' => self::SCHEMA_FIELD_NAME, 'type' => Type::non_null(self::_schema()), 'description' => 'Access the current type schema of this server.', 'args' => [], 'resolve' => static fn($source, array $args, $context, Resolve_Info $info): Schema => $info->schema]);
    }
    public static function type_meta_field_def(): Field_Definition
    {
        return self::$cached_instances[self::TYPE_FIELD_NAME] ??= new Field_Definition(['name' => self::TYPE_FIELD_NAME, 'type' => self::_type(), 'description' => 'Request the type information of a single type.', 'args' => [['name' => 'name', 'type' => Type::non_null(Type::string())]], 'resolve' => static fn($source, array $args, $context, Resolve_Info $info): ?Type => $info->schema->get_type($args['name'])]);
    }
    public static function type_name_meta_field_def(): Field_Definition
    {
        return self::$cached_instances[self::TYPE_NAME_FIELD_NAME] ??= new Field_Definition(['name' => self::TYPE_NAME_FIELD_NAME, 'type' => Type::non_null(Type::string()), 'description' => 'The name of the current Object type at runtime.', 'args' => [], 'resolve' => static fn($source, array $args, $context, Resolve_Info $info): string => $info->parent_type->name]);
    }
    public static function reset_cached_instances(): void
    {
        self::$cached_instances = null;
    }
}