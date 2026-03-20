<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Error\Syntax_Error;
use Graph_Ql\Language\Parser;
use Graph_Ql\Type\Definition\Custom_Scalar_Type;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Enum_Type;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Definition\Input_Object_Field;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Input_Type;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\List_Of_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Output_Type;
use Graph_Ql\Type\Definition\Scalar_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Definition\Union_Type;
use Graph_Ql\Type\Introspection;
use Graph_Ql\Type\Schema;
use Graph_Ql\Type\Schema_Config;
use Graph_Ql\Type\Type_Kind;
/**
 * @phpstan-import-type UnnamedFieldDefinitionConfig from FieldDefinition
 * @phpstan-import-type UnnamedInputObjectFieldConfig from InputObjectField
 *
 * @phpstan-type Options array{
 *   assumeValid?: bool
 * }
 *
 *    - assumeValid:
 *          When building a schema from a GraphQL service's introspection result, it
 *          might be safe to assume the schema is valid. Set to true to assume the
 *          produced schema is valid.
 *
 *          Default: false
 *
 * @see \GraphQL\Tests\Utils\BuildClientSchemaTest
 */
class Build_Client_Schema
{
    /** @var array<string, mixed> */
    private array $introspection;
    /**
     * @var array<string, bool>
     *
     * @phpstan-var Options
     */
    private array $options;
    /** @var array<string, NamedType&Type> */
    private array $type_map = [];
    /**
     * @param array<string, mixed> $introspectionQuery
     * @param array<string, bool> $options
     *
     * @phpstan-param Options    $options
     */
    public function __construct(array $introspection_query, array $options = [])
    {
        $this->introspection = $introspection_query;
        $this->options = $options;
    }
    /**
     * Build a schema for use by client tools.
     *
     * Given the result of a client running the introspection query, creates and
     * returns a \GraphQL\Type\Schema instance which can be then used with all graphql-php
     * tools, but cannot be used to execute a query, as introspection does not
     * represent the "resolver", "parse" or "serialize" functions or any other
     * server-internal mechanisms.
     *
     * This function expects a complete introspection result. Don't forget to check
     * the "errors" field of a server response before calling this function.
     *
     * @param array<string, mixed> $introspectionQuery
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @api
     *
     * @throws \Exception
     * @throws InvariantViolation
     */
    public static function build(array $introspection_query, array $options = []): Schema
    {
        return (new self($introspection_query, $options))->build_schema();
    }
    /**
     * @throws \Exception
     * @throws InvariantViolation
     */
    public function build_schema(): Schema
    {
        if (!array_key_exists('__schema', $this->introspection)) {
            $missing_schema_introspection = Utils::print_safe_json($this->introspection);
            throw new Invariant_Violation("Invalid or incomplete introspection result. Ensure that you are passing \"data\" property of introspection response and no \"errors\" was returned alongside: {$missing_schema_introspection}.");
        }
        $schema_introspection = $this->introspection['__schema'];
        $built_in_types = array_merge(Type::built_in_scalars(), Introspection::get_types());
        foreach ($schema_introspection['types'] as $type_introspection) {
            if (!isset($type_introspection['name'])) {
                throw self::invalid_or_incomplete_introspection_result($type_introspection);
            }
            $name = $type_introspection['name'];
            if (!is_string($name)) {
                throw self::invalid_or_incomplete_introspection_result($type_introspection);
            }
            // Use the built-in singleton types to avoid reconstruction
            $this->type_map[$name] = $built_in_types[$name] ?? $this->build_type($type_introspection);
        }
        $description = $schema_introspection['description'] ?? null;
        $query_type = isset($schema_introspection['queryType']) ? $this->get_object_type($schema_introspection['queryType']) : null;
        $mutation_type = isset($schema_introspection['mutationType']) ? $this->get_object_type($schema_introspection['mutationType']) : null;
        $subscription_type = isset($schema_introspection['subscriptionType']) ? $this->get_object_type($schema_introspection['subscriptionType']) : null;
        $directives = isset($schema_introspection['directives']) ? array_map([$this, 'buildDirective'], $schema_introspection['directives']) : [];
        return new Schema((new Schema_Config())->set_description($description)->set_query($query_type)->set_mutation($mutation_type)->set_subscription($subscription_type)->set_types($this->type_map)->set_directives($directives)->set_assume_valid($this->options['assumeValid'] ?? false));
    }
    /**
     * @param array<string, mixed> $typeRef
     *
     * @throws InvariantViolation
     */
    private function get_type(array $type_ref): Type
    {
        if (isset($type_ref['kind'])) {
            if ($type_ref['kind'] === Type_Kind::LIST) {
                if (!isset($type_ref['ofType'])) {
                    throw new Invariant_Violation('Decorated type deeper than introspection query.');
                }
                return new List_Of_Type($this->get_type($type_ref['ofType']));
            }
            if ($type_ref['kind'] === Type_Kind::NON_NULL) {
                if (!isset($type_ref['ofType'])) {
                    throw new Invariant_Violation('Decorated type deeper than introspection query.');
                }
                // @phpstan-ignore-next-line if the type is not a nullable type, schema validation will catch it
                return new Non_Null($this->get_type($type_ref['ofType']));
            }
        }
        if (!isset($type_ref['name'])) {
            $unknown_type_ref = Utils::print_safe_json($type_ref);
            throw new Invariant_Violation("Unknown type reference: {$unknown_type_ref}.");
        }
        return $this->get_named_type($type_ref['name']);
    }
    /**
     * @throws InvariantViolation
     *
     * @return NamedType&Type
     */
    private function get_named_type(string $type_name): Named_Type
    {
        if (!isset($this->type_map[$type_name])) {
            throw new Invariant_Violation("Invalid or incomplete schema, unknown type: {$type_name}. Ensure that a full introspection query is used in order to build a client schema.");
        }
        return $this->type_map[$type_name];
    }
    /** @param array<mixed> $type */
    public static function invalid_or_incomplete_introspection_result(array $type): Invariant_Violation
    {
        $incomplete_type = Utils::print_safe_json($type);
        return new Invariant_Violation("Invalid or incomplete introspection result. Ensure that a full introspection query is used in order to build a client schema: {$incomplete_type}.");
    }
    /**
     * @param array<string, mixed> $typeRef
     *
     * @throws InvariantViolation
     *
     * @return Type&InputType
     */
    private function get_input_type(array $type_ref): Input_Type
    {
        $type = $this->get_type($type_ref);
        if ($type instanceof Input_Type) {
            return $type;
        }
        $not_input_type = Utils::print_safe($type);
        throw new Invariant_Violation("Introspection must provide input type for arguments, but received: {$not_input_type}.");
    }
    /**
     * @param array<string, mixed> $typeRef
     *
     * @throws InvariantViolation
     */
    private function get_output_type(array $type_ref): Output_Type
    {
        $type = $this->get_type($type_ref);
        if ($type instanceof Output_Type) {
            return $type;
        }
        $not_input_type = Utils::print_safe($type);
        throw new Invariant_Violation("Introspection must provide output type for fields, but received: {$not_input_type}.");
    }
    /**
     * @param array<string, mixed> $typeRef
     *
     * @throws InvariantViolation
     */
    private function get_object_type(array $type_ref): Object_Type
    {
        $type = $this->get_type($type_ref);
        return Object_Type::assert_object_type($type);
    }
    /**
     * @param array<string, mixed> $typeRef
     *
     * @throws InvariantViolation
     */
    public function get_interface_type(array $type_ref): Interface_Type
    {
        $type = $this->get_type($type_ref);
        return Interface_Type::assert_interface_type($type);
    }
    /**
     * @param array<string, mixed> $type
     *
     * @throws InvariantViolation
     *
     * @return Type&NamedType
     */
    private function build_type(array $type): Named_Type
    {
        if (!array_key_exists('kind', $type)) {
            throw self::invalid_or_incomplete_introspection_result($type);
        }
        switch ($type['kind']) {
            case Type_Kind::SCALAR:
                return $this->build_scalar_def($type);
            case Type_Kind::OBJECT:
                return $this->build_object_def($type);
            case Type_Kind::INTERFACE:
                return $this->build_interface_def($type);
            case Type_Kind::UNION:
                return $this->build_union_def($type);
            case Type_Kind::ENUM:
                return $this->build_enum_def($type);
            case Type_Kind::INPUT_OBJECT:
                return $this->build_input_object_def($type);
            default:
                $unknown_kind_type = Utils::print_safe_json($type);
                throw new Invariant_Violation("Invalid or incomplete introspection result. Received type with unknown kind: {$unknown_kind_type}.");
        }
    }
    /**
     * @param array<string, string> $scalar
     *
     * @throws InvariantViolation
     */
    private function build_scalar_def(array $scalar): Scalar_Type
    {
        return new Custom_Scalar_Type(['name' => $scalar['name'], 'description' => $scalar['description'], 'serialize' => static fn($value) => $value]);
    }
    /**
     * @param array<string, mixed> $implementingIntrospection
     *
     * @throws InvariantViolation
     *
     * @return array<int, InterfaceType>
     */
    private function build_implementations_list(array $implementing_introspection): array
    {
        // TODO: Temporary workaround until GraphQL ecosystem will fully support 'interfaces' on interface types.
        if (array_key_exists('interfaces', $implementing_introspection) && $implementing_introspection['interfaces'] === null && $implementing_introspection['kind'] === Type_Kind::INTERFACE) {
            return [];
        }
        if (!array_key_exists('interfaces', $implementing_introspection)) {
            $safe_introspection = Utils::print_safe_json($implementing_introspection);
            throw new Invariant_Violation("Introspection result missing interfaces: {$safe_introspection}.");
        }
        return array_map([$this, 'getInterfaceType'], $implementing_introspection['interfaces']);
    }
    /**
     * @param array<string, mixed> $object
     *
     * @throws InvariantViolation
     */
    private function build_object_def(array $object): Object_Type
    {
        return new Object_Type(['name' => $object['name'], 'description' => $object['description'], 'interfaces' => fn(): array => $this->build_implementations_list($object), 'fields' => fn(): array => $this->build_field_def_map($object)]);
    }
    /**
     * @param array<string, mixed> $interface
     *
     * @throws InvariantViolation
     */
    private function build_interface_def(array $interface): Interface_Type
    {
        return new Interface_Type(['name' => $interface['name'], 'description' => $interface['description'], 'fields' => fn(): array => $this->build_field_def_map($interface), 'interfaces' => fn(): array => $this->build_implementations_list($interface)]);
    }
    /**
     * @param array<string, mixed> $union
     *
     * @throws InvariantViolation
     */
    private function build_union_def(array $union): Union_Type
    {
        if (!array_key_exists('possibleTypes', $union)) {
            $safe_union = Utils::print_safe_json($union);
            throw new Invariant_Violation("Introspection result missing possibleTypes: {$safe_union}.");
        }
        return new Union_Type(['name' => $union['name'], 'description' => $union['description'], 'types' => fn(): array => array_map([$this, 'getObjectType'], $union['possibleTypes'])]);
    }
    /**
     * @param array<string, mixed> $enum
     *
     * @throws InvariantViolation
     */
    private function build_enum_def(array $enum): Enum_Type
    {
        if (!array_key_exists('enumValues', $enum)) {
            $safe_enum = Utils::print_safe_json($enum);
            throw new Invariant_Violation("Introspection result missing enumValues: {$safe_enum}.");
        }
        $values = [];
        foreach ($enum['enumValues'] as $value) {
            $values[$value['name']] = ['description' => $value['description'], 'deprecationReason' => $value['deprecationReason']];
        }
        return new Enum_Type(['name' => $enum['name'], 'description' => $enum['description'], 'values' => $values]);
    }
    /**
     * @param array<string, mixed> $inputObject
     *
     * @throws InvariantViolation
     */
    private function build_input_object_def(array $input_object): Input_Object_Type
    {
        if (!array_key_exists('inputFields', $input_object)) {
            $safe_input_object = Utils::print_safe_json($input_object);
            throw new Invariant_Violation("Introspection result missing inputFields: {$safe_input_object}.");
        }
        return new Input_Object_Type(['name' => $input_object['name'], 'description' => $input_object['description'], 'fields' => fn(): array => $this->build_input_value_def_map($input_object['inputFields'])]);
    }
    /**
     * @param array<string, mixed> $typeIntrospection
     *
     * @throws \Exception
     * @throws InvariantViolation
     *
     * @return array<string, UnnamedFieldDefinitionConfig>
     */
    private function build_field_def_map(array $type_introspection): array
    {
        if (!array_key_exists('fields', $type_introspection)) {
            $safe_type = Utils::print_safe_json($type_introspection);
            throw new Invariant_Violation("Introspection result missing fields: {$safe_type}.");
        }
        /** @var array<string, UnnamedFieldDefinitionConfig> $map */
        $map = [];
        foreach ($type_introspection['fields'] as $field) {
            if (!array_key_exists('args', $field)) {
                $safe_field = Utils::print_safe_json($field);
                throw new Invariant_Violation("Introspection result missing field args: {$safe_field}.");
            }
            $map[$field['name']] = ['description' => $field['description'], 'deprecationReason' => $field['deprecationReason'], 'type' => $this->get_output_type($field['type']), 'args' => $this->build_input_value_def_map($field['args'])];
        }
        // @phpstan-ignore-next-line unless the returned name was numeric, this works
        return $map;
    }
    /**
     * @param array<int, array<string, mixed>> $inputValueIntrospections
     *
     * @throws \Exception
     *
     * @return array<string, UnnamedInputObjectFieldConfig>
     */
    private function build_input_value_def_map(array $input_value_introspections): array
    {
        /** @var array<string, UnnamedInputObjectFieldConfig> $map */
        $map = [];
        foreach ($input_value_introspections as $value) {
            $map[$value['name']] = $this->build_input_value($value);
        }
        return $map;
    }
    /**
     * @param array<string, mixed> $inputValueIntrospection
     *
     * @throws \Exception
     * @throws SyntaxError
     *
     * @return UnnamedInputObjectFieldConfig
     */
    public function build_input_value(array $input_value_introspection): array
    {
        $type = $this->get_input_type($input_value_introspection['type']);
        $input_value = ['description' => $input_value_introspection['description'], 'type' => $type];
        if (isset($input_value_introspection['defaultValue'])) {
            $input_value['defaultValue'] = AST::value_from_ast(Parser::parse_value($input_value_introspection['defaultValue']), $type);
        }
        return $input_value;
    }
    /**
     * @param array<string, mixed> $directive
     *
     * @throws \Exception
     * @throws InvariantViolation
     */
    public function build_directive(array $directive): Directive
    {
        if (!array_key_exists('args', $directive)) {
            $safe_directive = Utils::print_safe_json($directive);
            throw new Invariant_Violation("Introspection result missing directive args: {$safe_directive}.");
        }
        if (!array_key_exists('locations', $directive)) {
            $safe_directive = Utils::print_safe_json($directive);
            throw new Invariant_Violation("Introspection result missing directive locations: {$safe_directive}.");
        }
        return new Directive(['name' => $directive['name'], 'description' => $directive['description'], 'args' => $this->build_input_value_def_map($directive['args']), 'isRepeatable' => $directive['isRepeatable'] ?? false, 'locations' => $directive['locations']]);
    }
}