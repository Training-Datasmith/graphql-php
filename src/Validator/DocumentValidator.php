<?php

declare (strict_types=1);
namespace Graph_Ql\Validator;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\Type_Info;
use Graph_Ql\Validator\Rules\Disable_Introspection;
use Graph_Ql\Validator\Rules\Executable_Definitions;
use Graph_Ql\Validator\Rules\Fields_On_Correct_Type;
use Graph_Ql\Validator\Rules\Fragments_On_Composite_Types;
use Graph_Ql\Validator\Rules\Known_Argument_Names;
use Graph_Ql\Validator\Rules\Known_Argument_Names_On_Directives;
use Graph_Ql\Validator\Rules\Known_Directives;
use Graph_Ql\Validator\Rules\Known_Fragment_Names;
use Graph_Ql\Validator\Rules\Known_Type_Names;
use Graph_Ql\Validator\Rules\Lone_Anonymous_Operation;
use Graph_Ql\Validator\Rules\Lone_Schema_Definition;
use Graph_Ql\Validator\Rules\No_Fragment_Cycles;
use Graph_Ql\Validator\Rules\No_Undefined_Variables;
use Graph_Ql\Validator\Rules\No_Unused_Fragments;
use Graph_Ql\Validator\Rules\No_Unused_Variables;
use Graph_Ql\Validator\Rules\One_Of_Input_Objects_Rule;
use Graph_Ql\Validator\Rules\Overlapping_Fields_Can_Be_Merged;
use Graph_Ql\Validator\Rules\Possible_Fragment_Spreads;
use Graph_Ql\Validator\Rules\Possible_Type_Extensions;
use Graph_Ql\Validator\Rules\Provided_Required_Arguments;
use Graph_Ql\Validator\Rules\Provided_Required_Arguments_On_Directives;
use Graph_Ql\Validator\Rules\Query_Complexity;
use Graph_Ql\Validator\Rules\Query_Depth;
use Graph_Ql\Validator\Rules\Query_Security_Rule;
use Graph_Ql\Validator\Rules\Scalar_Leafs;
use Graph_Ql\Validator\Rules\Single_Field_Subscription;
use Graph_Ql\Validator\Rules\Unique_Argument_Definition_Names;
use Graph_Ql\Validator\Rules\Unique_Argument_Names;
use Graph_Ql\Validator\Rules\Unique_Directive_Names;
use Graph_Ql\Validator\Rules\Unique_Directives_Per_Location;
use Graph_Ql\Validator\Rules\Unique_Enum_Value_Names;
use Graph_Ql\Validator\Rules\Unique_Field_Definition_Names;
use Graph_Ql\Validator\Rules\Unique_Fragment_Names;
use Graph_Ql\Validator\Rules\Unique_Input_Field_Names;
use Graph_Ql\Validator\Rules\Unique_Operation_Names;
use Graph_Ql\Validator\Rules\Unique_Operation_Types;
use Graph_Ql\Validator\Rules\Unique_Type_Names;
use Graph_Ql\Validator\Rules\Unique_Variable_Names;
use Graph_Ql\Validator\Rules\Validation_Rule;
use Graph_Ql\Validator\Rules\Values_Of_Correct_Type;
use Graph_Ql\Validator\Rules\Variables_Are_Input_Types;
use Graph_Ql\Validator\Rules\Variables_In_Allowed_Position;
/**
 * Implements the "Validation" section of the spec.
 *
 * Validation runs synchronously, returning an array of encountered errors, or
 * an empty array if no errors were encountered and the document is valid.
 *
 * A list of specific validation rules may be provided. If not provided, the
 * default list of rules defined by the GraphQL specification will be used.
 *
 * Each validation rule is an instance of GraphQL\Validator\Rules\ValidationRule
 * which returns a visitor (see the [GraphQL\Language\Visitor API](class-reference.md#graphqllanguagevisitor)).
 *
 * Visitor methods are expected to return an instance of [GraphQL\Error\Error](class-reference.md#graphqlerrorerror),
 * or array of such instances when invalid.
 *
 * Optionally a custom TypeInfo instance may be provided. If not provided, one
 * will be created from the provided schema.
 */
class Document_Validator
{
    /** @var array<string, ValidationRule> */
    private static array $rules = [];
    /** @var array<class-string<ValidationRule>, ValidationRule> */
    private static array $default_rules;
    /** @var array<class-string<QuerySecurityRule>, QuerySecurityRule> */
    private static array $security_rules;
    /** @var array<class-string<ValidationRule>, ValidationRule> */
    private static array $sdl_rules;
    private static bool $init_rules = false;
    /**
     * Validate a GraphQL query against a schema.
     *
     * @param array<ValidationRule>|null $rules Defaults to using all available rules
     *
     * @throws \Exception
     *
     * @return list<Error>
     *
     * @api
     */
    public static function validate(Schema $schema, Document_Node $ast, ?array $rules = null, ?Type_Info $type_info = null): array
    {
        $rules ??= static::all_rules();
        if ($rules === []) {
            return [];
        }
        $type_info ??= new Type_Info($schema);
        $context = new Query_Validation_Context($schema, $ast, $type_info);
        $visitors = [];
        foreach ($rules as $rule) {
            $visitors[] = $rule->get_visitor($context);
        }
        Visitor::visit($ast, Visitor::visit_with_type_info($type_info, Visitor::visit_in_parallel($visitors)));
        return $context->get_errors();
    }
    /**
     * Returns all global validation rules.
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, ValidationRule>
     *
     * @api
     */
    public static function all_rules(): array
    {
        if (!self::$init_rules) {
            self::$rules = array_merge(static::default_rules(), self::security_rules(), self::$rules);
            self::$init_rules = true;
        }
        return self::$rules;
    }
    /** @return array<class-string<ValidationRule>, ValidationRule> */
    public static function default_rules(): array
    {
        return self::$default_rules ??= [Executable_Definitions::class => new Executable_Definitions(), Unique_Operation_Names::class => new Unique_Operation_Names(), Lone_Anonymous_Operation::class => new Lone_Anonymous_Operation(), Single_Field_Subscription::class => new Single_Field_Subscription(), Known_Type_Names::class => new Known_Type_Names(), Fragments_On_Composite_Types::class => new Fragments_On_Composite_Types(), Variables_Are_Input_Types::class => new Variables_Are_Input_Types(), Scalar_Leafs::class => new Scalar_Leafs(), Fields_On_Correct_Type::class => new Fields_On_Correct_Type(), Unique_Fragment_Names::class => new Unique_Fragment_Names(), Known_Fragment_Names::class => new Known_Fragment_Names(), No_Unused_Fragments::class => new No_Unused_Fragments(), Possible_Fragment_Spreads::class => new Possible_Fragment_Spreads(), No_Fragment_Cycles::class => new No_Fragment_Cycles(), Unique_Variable_Names::class => new Unique_Variable_Names(), No_Undefined_Variables::class => new No_Undefined_Variables(), No_Unused_Variables::class => new No_Unused_Variables(), Known_Directives::class => new Known_Directives(), Unique_Directives_Per_Location::class => new Unique_Directives_Per_Location(), Known_Argument_Names::class => new Known_Argument_Names(), Unique_Argument_Names::class => new Unique_Argument_Names(), Values_Of_Correct_Type::class => new Values_Of_Correct_Type(), Provided_Required_Arguments::class => new Provided_Required_Arguments(), Variables_In_Allowed_Position::class => new Variables_In_Allowed_Position(), Overlapping_Fields_Can_Be_Merged::class => new Overlapping_Fields_Can_Be_Merged(), Unique_Input_Field_Names::class => new Unique_Input_Field_Names(), One_Of_Input_Objects_Rule::class => new One_Of_Input_Objects_Rule()];
    }
    /**
     * @deprecated just add rules via @see DocumentValidator::addRule()
     *
     * @throws \InvalidArgumentException
     *
     * @return array<class-string<QuerySecurityRule>, QuerySecurityRule>
     */
    public static function security_rules(): array
    {
        return self::$security_rules ??= [Disable_Introspection::class => new Disable_Introspection(Disable_Introspection::DISABLED), Query_Depth::class => new Query_Depth(Query_Depth::DISABLED), Query_Complexity::class => new Query_Complexity(Query_Complexity::DISABLED)];
    }
    /** @return array<class-string<ValidationRule>, ValidationRule> */
    public static function sdl_rules(): array
    {
        return self::$sdl_rules ??= [Lone_Schema_Definition::class => new Lone_Schema_Definition(), Unique_Operation_Types::class => new Unique_Operation_Types(), Unique_Type_Names::class => new Unique_Type_Names(), Unique_Enum_Value_Names::class => new Unique_Enum_Value_Names(), Unique_Field_Definition_Names::class => new Unique_Field_Definition_Names(), Unique_Argument_Definition_Names::class => new Unique_Argument_Definition_Names(), Unique_Directive_Names::class => new Unique_Directive_Names(), Known_Type_Names::class => new Known_Type_Names(), Known_Directives::class => new Known_Directives(), Unique_Directives_Per_Location::class => new Unique_Directives_Per_Location(), Possible_Type_Extensions::class => new Possible_Type_Extensions(), Known_Argument_Names_On_Directives::class => new Known_Argument_Names_On_Directives(), Unique_Argument_Names::class => new Unique_Argument_Names(), Unique_Input_Field_Names::class => new Unique_Input_Field_Names(), Provided_Required_Arguments_On_Directives::class => new Provided_Required_Arguments_On_Directives()];
    }
    /**
     * Returns global validation rule by name.
     *
     * Standard rules are named by class name, so example usage for such rules:
     *
     * @example DocumentValidator::getRule(GraphQL\Validator\Rules\QueryComplexity::class);
     *
     * @api
     *
     * @throws \InvalidArgumentException
     */
    public static function get_rule(string $name): ?Validation_Rule
    {
        return static::all_rules()[$name] ?? null;
    }
    /**
     * Add rule to list of global validation rules.
     *
     * @api
     */
    public static function add_rule(Validation_Rule $rule): void
    {
        self::$rules[$rule->get_name()] = $rule;
    }
    /**
     * Remove rule from list of global validation rules.
     *
     * @api
     */
    public static function remove_rule(Validation_Rule $rule): void
    {
        unset(self::$rules[$rule->get_name()]);
    }
    /**
     * Validate a GraphQL document defined through schema definition language.
     *
     * @param array<ValidationRule>|null $rules
     *
     * @throws \Exception
     *
     * @return list<Error>
     */
    public static function validate_sdl(Document_Node $document_ast, ?Schema $schema_to_extend = null, ?array $rules = null): array
    {
        $rules ??= self::sdl_rules();
        if ($rules === []) {
            return [];
        }
        $context = new Sdl_Validation_Context($document_ast, $schema_to_extend);
        $visitors = [];
        foreach ($rules as $rule) {
            $visitors[] = $rule->get_sdl_visitor($context);
        }
        Visitor::visit($document_ast, Visitor::visit_in_parallel($visitors));
        return $context->get_errors();
    }
    /**
     * @throws \Exception
     * @throws Error
     */
    public static function assert_valid_sdl(Document_Node $document_ast): void
    {
        $errors = self::validate_sdl($document_ast);
        if ($errors !== []) {
            throw new Error(self::combine_error_messages($errors));
        }
    }
    /**
     * @throws \Exception
     * @throws Error
     */
    public static function assert_valid_sdl_extension(Document_Node $document_ast, Schema $schema): void
    {
        $errors = self::validate_sdl($document_ast, $schema);
        if ($errors !== []) {
            throw new Error(self::combine_error_messages($errors));
        }
    }
    /** @param array<Error> $errors */
    private static function combine_error_messages(array $errors): string
    {
        $messages = [];
        foreach ($errors as $error) {
            $messages[] = $error->get_message();
        }
        return implode("\n\n", $messages);
    }
}