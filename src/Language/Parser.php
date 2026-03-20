<?php

declare (strict_types=1);
namespace Graph_Ql\Language;

use Graph_Ql\Error\Syntax_Error;
use Graph_Ql\Language\AST\Argument_Node;
use Graph_Ql\Language\AST\Boolean_Value_Node;
use Graph_Ql\Language\AST\Definition_Node;
use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\AST\Directive_Node;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\AST\Enum_Type_Definition_Node;
use Graph_Ql\Language\AST\Enum_Type_Extension_Node;
use Graph_Ql\Language\AST\Enum_Value_Definition_Node;
use Graph_Ql\Language\AST\Enum_Value_Node;
use Graph_Ql\Language\AST\Executable_Definition_Node;
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
use Graph_Ql\Language\AST\Location;
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
use Graph_Ql\Language\AST\Selection_Node;
use Graph_Ql\Language\AST\Selection_Set_Node;
use Graph_Ql\Language\AST\String_Value_Node;
use Graph_Ql\Language\AST\Type_Extension_Node;
use Graph_Ql\Language\AST\Type_Node;
use Graph_Ql\Language\AST\Type_System_Definition_Node;
use Graph_Ql\Language\AST\Type_System_Extension_Node;
use Graph_Ql\Language\AST\Union_Type_Definition_Node;
use Graph_Ql\Language\AST\Union_Type_Extension_Node;
use Graph_Ql\Language\AST\Value_Node;
use Graph_Ql\Language\AST\Variable_Definition_Node;
use Graph_Ql\Language\AST\Variable_Node;
/**
 * Parses string containing GraphQL query language or [schema definition language](schema-definition-language.md) to Abstract Syntax Tree.
 *
 * @phpstan-type ParserOptions array{
 *   noLocation?: bool,
 *   allowLegacySDLEmptyFields?: bool,
 *   allowLegacySDLImplementsInterfaces?: bool,
 *   experimentalFragmentVariables?: bool
 * }
 *
 * - **noLocation**:
 *   By default, the parser creates AST nodes that know the location in the source.
 *   This configuration flag disables that behavior for performance or testing.
 *
 * - **allowLegacySDLEmptyFields**:
 *   If enabled, the parser will parse empty fields sets in the Schema Definition Language.
 *   Otherwise, the parser will follow the current specification.
 *   This option is provided to ease adoption of the final SDL specification and will be removed in a future major release.
 *
 * - **allowLegacySDLImplementsInterfaces**:
 *   If enabled, the parser will parse implemented interfaces with no `&` character between each interface.
 *   Otherwise, the parser will follow the current specification.
 *   This option is provided to ease adoption of the final SDL specification and will be removed in a future major release.
 *
 * - **experimentalFragmentVariables**:
 *   If enabled, the parser will understand and parse variable definitions contained in a fragment definition.
 *   They'll be represented in the `variableDefinitions` field of the FragmentDefinitionNode.
 *   The syntax is identical to normal, query-defined variables. For example:
 *
 *   ```graphql
 *   fragment A($var: Boolean = false) on T {
 *     ...
 *   }
 *   ```
 *
 *   Note: this feature is experimental and may change or be removed in the future.
 *
 * Those magic functions allow partial parsing:
 *
 * @method static NameNode name(Source|string $source, ParserOptions $options = [])
 * @method static ExecutableDefinitionNode|TypeSystemDefinitionNode definition(Source|string $source, ParserOptions $options = [])
 * @method static ExecutableDefinitionNode executableDefinition(Source|string $source, ParserOptions $options = [])
 * @method static OperationDefinitionNode operationDefinition(Source|string $source, ParserOptions $options = [])
 * @method static string operationType(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<VariableDefinitionNode> variableDefinitions(Source|string $source, ParserOptions $options = [])
 * @method static VariableDefinitionNode variableDefinition(Source|string $source, ParserOptions $options = [])
 * @method static VariableNode variable(Source|string $source, ParserOptions $options = [])
 * @method static SelectionSetNode selectionSet(Source|string $source, ParserOptions $options = [])
 * @method static mixed selection(Source|string $source, ParserOptions $options = [])
 * @method static FieldNode field(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<ArgumentNode> arguments(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<ArgumentNode> constArguments(Source|string $source, ParserOptions $options = [])
 * @method static ArgumentNode argument(Source|string $source, ParserOptions $options = [])
 * @method static ArgumentNode constArgument(Source|string $source, ParserOptions $options = [])
 * @method static FragmentSpreadNode|InlineFragmentNode fragment(Source|string $source, ParserOptions $options = [])
 * @method static FragmentDefinitionNode fragmentDefinition(Source|string $source, ParserOptions $options = [])
 * @method static NameNode fragmentName(Source|string $source, ParserOptions $options = [])
 * @method static BooleanValueNode|EnumValueNode|FloatValueNode|IntValueNode|ListValueNode|NullValueNode|ObjectValueNode|StringValueNode|VariableNode valueLiteral(Source|string $source, ParserOptions $options = [])
 * @method static BooleanValueNode|EnumValueNode|FloatValueNode|IntValueNode|ListValueNode|NullValueNode|ObjectValueNode|StringValueNode constValueLiteral(Source|string $source, ParserOptions $options = [])
 * @method static StringValueNode stringLiteral(Source|string $source, ParserOptions $options = [])
 * @method static BooleanValueNode|EnumValueNode|FloatValueNode|IntValueNode|StringValueNode constValue(Source|string $source, ParserOptions $options = [])
 * @method static BooleanValueNode|EnumValueNode|FloatValueNode|IntValueNode|ListValueNode|ObjectValueNode|StringValueNode|VariableNode variableValue(Source|string $source, ParserOptions $options = [])
 * @method static ListValueNode array(Source|string $source, ParserOptions $options = [])
 * @method static ListValueNode constArray(Source|string $source, ParserOptions $options = [])
 * @method static ObjectValueNode object(Source|string $source, ParserOptions $options = [])
 * @method static ObjectValueNode constObject(Source|string $source, ParserOptions $options = [])
 * @method static ObjectFieldNode objectField(Source|string $source, ParserOptions $options = [])
 * @method static ObjectFieldNode constObjectField(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<DirectiveNode> directives(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<DirectiveNode> constDirectives(Source|string $source, ParserOptions $options = [])
 * @method static DirectiveNode directive(Source|string $source, ParserOptions $options = [])
 * @method static DirectiveNode constDirective(Source|string $source, ParserOptions $options = [])
 * @method static ListTypeNode|NamedTypeNode|NonNullTypeNode typeReference(Source|string $source, ParserOptions $options = [])
 * @method static NamedTypeNode namedType(Source|string $source, ParserOptions $options = [])
 * @method static TypeSystemDefinitionNode typeSystemDefinition(Source|string $source, ParserOptions $options = [])
 * @method static StringValueNode|null description(Source|string $source, ParserOptions $options = [])
 * @method static SchemaDefinitionNode schemaDefinition(Source|string $source, ParserOptions $options = [])
 * @method static OperationTypeDefinitionNode operationTypeDefinition(Source|string $source, ParserOptions $options = [])
 * @method static ScalarTypeDefinitionNode scalarTypeDefinition(Source|string $source, ParserOptions $options = [])
 * @method static ObjectTypeDefinitionNode objectTypeDefinition(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<NamedTypeNode> implementsInterfaces(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<FieldDefinitionNode> fieldsDefinition(Source|string $source, ParserOptions $options = [])
 * @method static FieldDefinitionNode fieldDefinition(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<InputValueDefinitionNode> argumentsDefinition(Source|string $source, ParserOptions $options = [])
 * @method static InputValueDefinitionNode inputValueDefinition(Source|string $source, ParserOptions $options = [])
 * @method static InterfaceTypeDefinitionNode interfaceTypeDefinition(Source|string $source, ParserOptions $options = [])
 * @method static UnionTypeDefinitionNode unionTypeDefinition(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<NamedTypeNode> unionMemberTypes(Source|string $source, ParserOptions $options = [])
 * @method static EnumTypeDefinitionNode enumTypeDefinition(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<EnumValueDefinitionNode> enumValuesDefinition(Source|string $source, ParserOptions $options = [])
 * @method static EnumValueDefinitionNode enumValueDefinition(Source|string $source, ParserOptions $options = [])
 * @method static InputObjectTypeDefinitionNode inputObjectTypeDefinition(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<InputValueDefinitionNode> inputFieldsDefinition(Source|string $source, ParserOptions $options = [])
 * @method static TypeExtensionNode typeExtension(Source|string $source, ParserOptions $options = [])
 * @method static SchemaExtensionNode schemaTypeExtension(Source|string $source, ParserOptions $options = [])
 * @method static ScalarTypeExtensionNode scalarTypeExtension(Source|string $source, ParserOptions $options = [])
 * @method static ObjectTypeExtensionNode objectTypeExtension(Source|string $source, ParserOptions $options = [])
 * @method static InterfaceTypeExtensionNode interfaceTypeExtension(Source|string $source, ParserOptions $options = [])
 * @method static UnionTypeExtensionNode unionTypeExtension(Source|string $source, ParserOptions $options = [])
 * @method static EnumTypeExtensionNode enumTypeExtension(Source|string $source, ParserOptions $options = [])
 * @method static InputObjectTypeExtensionNode inputObjectTypeExtension(Source|string $source, ParserOptions $options = [])
 * @method static DirectiveDefinitionNode directiveDefinition(Source|string $source, ParserOptions $options = [])
 * @method static NodeList<NameNode> directiveLocations(Source|string $source, ParserOptions $options = [])
 * @method static NameNode directiveLocation(Source|string $source, ParserOptions $options = [])
 *
 * @see \GraphQL\Tests\Language\ParserTest
 */
class Parser
{
    /**
     * Given a GraphQL source, parses it into a `GraphQL\Language\AST\DocumentNode`.
     *
     * Throws `GraphQL\Error\SyntaxError` if a syntax error is encountered.
     *
     * @param Source|string $source
     *
     * @phpstan-param ParserOptions $options
     *
     * @api
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    public static function parse($source, array $options = []): Document_Node
    {
        return (new self($source, $options))->parse_document();
    }
    /**
     * Given a string containing a GraphQL value (ex. `[42]`), parse the AST for that value.
     *
     * Throws `GraphQL\Error\SyntaxError` if a syntax error is encountered.
     *
     * This is useful within tools that operate upon GraphQL Values directly and
     * in isolation of complete GraphQL documents.
     *
     * Consider providing the results to the utility function: `GraphQL\Utils\AST::valueFromAST()`.
     *
     * @param Source|string $source
     *
     * @phpstan-param ParserOptions $options
     *
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return BooleanValueNode|EnumValueNode|FloatValueNode|IntValueNode|ListValueNode|NullValueNode|ObjectValueNode|StringValueNode|VariableNode
     *
     * @api
     */
    public static function parse_value($source, array $options = [])
    {
        $parser = new Parser($source, $options);
        $parser->expect(Token::SOF);
        $value = $parser->parse_value_literal(false);
        $parser->expect(Token::EOF);
        return $value;
    }
    /**
     * Given a string containing a GraphQL Type (ex. `[Int!]`), parse the AST for that type.
     *
     * Throws `GraphQL\Error\SyntaxError` if a syntax error is encountered.
     *
     * This is useful within tools that operate upon GraphQL Types directly and
     * in isolation of complete GraphQL documents.
     *
     * Consider providing the results to the utility function: `GraphQL\Utils\AST::typeFromAST()`.
     *
     * @param Source|string $source
     *
     * @phpstan-param ParserOptions $options
     *
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return ListTypeNode|NamedTypeNode|NonNullTypeNode
     *
     * @api
     */
    public static function parse_type($source, array $options = [])
    {
        $parser = new Parser($source, $options);
        $parser->expect(Token::SOF);
        $type = $parser->parse_type_reference();
        $parser->expect(Token::EOF);
        return $type;
    }
    /**
     * Parse partial source by delegating calls to the internal parseX methods.
     *
     * @phpstan-param array{string, ParserOptions} $arguments
     *
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return Node|NodeList<Node>
     */
    public static function __callStatic(string $name, array $arguments)
    {
        $parser = new Parser(...$arguments);
        $parser->expect(Token::SOF);
        switch ($name) {
            case 'arguments':
                $parsed = $parser->parse_arguments(false);
                break;
            case 'valueLiteral':
                $parsed = $parser->parse_value_literal(false);
                break;
            case 'array':
                $parsed = $parser->parse_array(false);
                break;
            case 'object':
                $parsed = $parser->parse_object(false);
                break;
            case 'objectField':
                $parsed = $parser->parse_object_field(false);
                break;
            case 'directives':
                $parsed = $parser->parse_directives(false);
                break;
            case 'directive':
                $parsed = $parser->parse_directive(false);
                break;
            case 'constArguments':
                $parsed = $parser->parse_arguments(true);
                break;
            case 'constValueLiteral':
                $parsed = $parser->parse_value_literal(true);
                break;
            case 'constArray':
                $parsed = $parser->parse_array(true);
                break;
            case 'constObject':
                $parsed = $parser->parse_object(true);
                break;
            case 'constObjectField':
                $parsed = $parser->parse_object_field(true);
                break;
            case 'constDirectives':
                $parsed = $parser->parse_directives(true);
                break;
            case 'constDirective':
                $parsed = $parser->parse_directive(true);
                break;
            default:
                $parsed = $parser->{'parse' . $name}();
        }
        $parser->expect(Token::EOF);
        return $parsed;
    }
    private Lexer $lexer;
    /**
     * @param Source|string $source
     *
     * @phpstan-param ParserOptions        $options
     */
    public function __construct($source, array $options = [])
    {
        $source_obj = $source instanceof Source ? $source : new Source($source);
        $this->lexer = new Lexer($source_obj, $options);
    }
    /**
     * Returns a location object, used to identify the place in
     * the source that created a given parsed object.
     */
    private function loc(Token $start_token): ?Location
    {
        if (!($this->lexer->options['noLocation'] ?? false)) {
            return new Location($start_token, $this->lexer->last_token, $this->lexer->source);
        }
        return null;
    }
    /** Determines if the next token is of a given kind. */
    private function peek(string $kind): bool
    {
        return $this->lexer->token->kind === $kind;
    }
    /**
     * If the next token is of the given kind, return true after advancing
     * the parser. Otherwise, do not change the parser state and return false.
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function skip(string $kind): bool
    {
        $match = $this->lexer->token->kind === $kind;
        if ($match) {
            $this->lexer->advance();
        }
        return $match;
    }
    /**
     * If the next token is of the given kind, return that token after advancing
     * the parser. Otherwise, do not change the parser state and return false.
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function expect(string $kind): Token
    {
        $token = $this->lexer->token;
        if ($token->kind === $kind) {
            $this->lexer->advance();
            return $token;
        }
        throw new Syntax_Error($this->lexer->source, $token->start, "Expected {$kind}, found {$token->get_description()}");
    }
    /**
     * If the next token is a keyword with the given value, advance the lexer.
     * Otherwise, throw an error.
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function expect_keyword(string $value): void
    {
        $token = $this->lexer->token;
        if ($token->kind !== Token::NAME || $token->value !== $value) {
            throw new Syntax_Error($this->lexer->source, $token->start, "Expected \"{$value}\", found {$token->get_description()}");
        }
        $this->lexer->advance();
    }
    /**
     * If the next token is a given keyword, return "true" after advancing
     * the lexer. Otherwise, do not change the parser state and return "false".
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function expect_optional_keyword(string $value): bool
    {
        $token = $this->lexer->token;
        if ($token->kind === Token::NAME && $token->value === $value) {
            $this->lexer->advance();
            return true;
        }
        return false;
    }
    private function unexpected(?Token $at_token = null): Syntax_Error
    {
        $token = $at_token ?? $this->lexer->token;
        return new Syntax_Error($this->lexer->source, $token->start, 'Unexpected ' . $token->get_description());
    }
    /**
     * Returns a possibly empty list of parse nodes, determined by
     * the parseFn. This list begins with a lex token of openKind
     * and ends with a lex token of closeKind. Advances the parser
     * to the next lex token after the closing token.
     *
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<Node>
     */
    private function any(string $open_kind, callable $parse_fn, string $close_kind): Node_List
    {
        $this->expect($open_kind);
        $nodes = [];
        while (!$this->skip($close_kind)) {
            $nodes[] = $parse_fn($this);
        }
        return new Node_List($nodes);
    }
    /**
     * Returns a non-empty list of parse nodes, determined by
     * the parseFn. This list begins with a lex token of openKind
     * and ends with a lex token of closeKind. Advances the parser
     * to the next lex token after the closing token.
     *
     * @template TNode of Node
     *
     * @param callable(self): TNode $parseFn
     *
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<TNode>
     */
    private function many(string $open_kind, callable $parse_fn, string $close_kind): Node_List
    {
        $this->expect($open_kind);
        $nodes = [$parse_fn($this)];
        while (!$this->skip($close_kind)) {
            $nodes[] = $parse_fn($this);
        }
        return new Node_List($nodes);
    }
    /**
     * Converts a name lex token into a name parse node.
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_name(): Name_Node
    {
        $token = $this->expect(Token::NAME);
        return new Name_Node(['value' => $token->value, 'loc' => $this->loc($token)]);
    }
    /**
     * Implements the parsing rules in the Document section.
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_document(): Document_Node
    {
        $start = $this->lexer->token;
        return new Document_Node(['definitions' => $this->many(Token::SOF, fn(): Definition_Node => $this->parse_definition(), Token::EOF), 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return DefinitionNode&Node
     */
    private function parse_definition(): Definition_Node
    {
        if ($this->peek(Token::NAME)) {
            switch ($this->lexer->token->value) {
                case 'query':
                case 'mutation':
                case 'subscription':
                case 'fragment':
                    return $this->parse_executable_definition();
                // Note: The schema definition language is an experimental addition.
                case 'schema':
                case 'scalar':
                case 'type':
                case 'interface':
                case 'union':
                case 'enum':
                case 'input':
                case 'directive':
                    // Note: The schema definition language is an experimental addition.
                    return $this->parse_type_system_definition();
                case 'extend':
                    return $this->parse_type_system_extension();
            }
        } elseif ($this->peek(Token::BRACE_L)) {
            return $this->parse_executable_definition();
        } elseif ($this->peek_description()) {
            // Note: The schema definition language is an experimental addition.
            return $this->parse_type_system_definition();
        }
        throw $this->unexpected();
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return ExecutableDefinitionNode&Node
     */
    private function parse_executable_definition(): Executable_Definition_Node
    {
        if ($this->peek(Token::NAME)) {
            switch ($this->lexer->token->value) {
                case 'query':
                case 'mutation':
                case 'subscription':
                    return $this->parse_operation_definition();
                case 'fragment':
                    return $this->parse_fragment_definition();
            }
        } elseif ($this->peek(Token::BRACE_L)) {
            return $this->parse_operation_definition();
        }
        throw $this->unexpected();
    }
    // Implements the parsing rules in the Operations section.
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_operation_definition(): Operation_Definition_Node
    {
        $start = $this->lexer->token;
        if ($this->peek(Token::BRACE_L)) {
            return new Operation_Definition_Node(['name' => null, 'operation' => 'query', 'variableDefinitions' => new Node_List([]), 'directives' => new Node_List([]), 'selectionSet' => $this->parse_selection_set(), 'loc' => $this->loc($start)]);
        }
        $operation = $this->parse_operation_type();
        $name = null;
        if ($this->peek(Token::NAME)) {
            $name = $this->parse_name();
        }
        return new Operation_Definition_Node(['name' => $name, 'operation' => $operation, 'variableDefinitions' => $this->parse_variable_definitions(), 'directives' => $this->parse_directives(false), 'selectionSet' => $this->parse_selection_set(), 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_operation_type(): string
    {
        $operation_token = $this->expect(Token::NAME);
        switch ($operation_token->value) {
            case 'query':
                return 'query';
            case 'mutation':
                return 'mutation';
            case 'subscription':
                return 'subscription';
        }
        throw $this->unexpected($operation_token);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<VariableDefinitionNode>
     */
    private function parse_variable_definitions(): Node_List
    {
        return $this->peek(Token::PAREN_L) ? $this->many(Token::PAREN_L, fn(): Variable_Definition_Node => $this->parse_variable_definition(), Token::PAREN_R) : new Node_List([]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_variable_definition(): Variable_Definition_Node
    {
        $start = $this->lexer->token;
        $var = $this->parse_variable();
        $this->expect(Token::COLON);
        $type = $this->parse_type_reference();
        return new Variable_Definition_Node(['variable' => $var, 'type' => $type, 'defaultValue' => $this->skip(Token::EQUALS) ? $this->parse_value_literal(true) : null, 'directives' => $this->parse_directives(true), 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_variable(): Variable_Node
    {
        $start = $this->lexer->token;
        $this->expect(Token::DOLLAR);
        return new Variable_Node(['name' => $this->parse_name(), 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_selection_set(): Selection_Set_Node
    {
        $start = $this->lexer->token;
        return new Selection_Set_Node(['selections' => $this->many(Token::BRACE_L, fn(): Selection_Node => $this->parse_selection(), Token::BRACE_R), 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return SelectionNode&Node
     */
    private function parse_selection(): Selection_Node
    {
        return $this->peek(Token::SPREAD) ? $this->parse_fragment() : $this->parse_field();
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_field(): Field_Node
    {
        $start = $this->lexer->token;
        $name_or_alias = $this->parse_name();
        if ($this->skip(Token::COLON)) {
            $alias = $name_or_alias;
            $name = $this->parse_name();
        } else {
            $alias = null;
            $name = $name_or_alias;
        }
        return new Field_Node(['name' => $name, 'alias' => $alias, 'arguments' => $this->parse_arguments(false), 'directives' => $this->parse_directives(false), 'selectionSet' => $this->peek(Token::BRACE_L) ? $this->parse_selection_set() : null, 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<ArgumentNode>
     */
    private function parse_arguments(bool $is_const): Node_List
    {
        $parse_fn = $is_const ? fn(): Argument_Node => $this->parse_const_argument() : fn(): Argument_Node => $this->parse_argument();
        return $this->peek(Token::PAREN_L) ? $this->many(Token::PAREN_L, $parse_fn, Token::PAREN_R) : new Node_List([]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_argument(): Argument_Node
    {
        $start = $this->lexer->token;
        $name = $this->parse_name();
        $this->expect(Token::COLON);
        $value = $this->parse_value_literal(false);
        return new Argument_Node(['name' => $name, 'value' => $value, 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_const_argument(): Argument_Node
    {
        $start = $this->lexer->token;
        $name = $this->parse_name();
        $this->expect(Token::COLON);
        $value = $this->parse_const_value();
        return new Argument_Node(['name' => $name, 'value' => $value, 'loc' => $this->loc($start)]);
    }
    // Implements the parsing rules in the Fragments section.
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return FragmentSpreadNode|InlineFragmentNode
     */
    private function parse_fragment(): Selection_Node
    {
        $start = $this->lexer->token;
        $this->expect(Token::SPREAD);
        $has_type_condition = $this->expect_optional_keyword('on');
        if (!$has_type_condition && $this->peek(Token::NAME)) {
            return new Fragment_Spread_Node(['name' => $this->parse_fragment_name(), 'directives' => $this->parse_directives(false), 'loc' => $this->loc($start)]);
        }
        return new Inline_Fragment_Node(['typeCondition' => $has_type_condition ? $this->parse_named_type() : null, 'directives' => $this->parse_directives(false), 'selectionSet' => $this->parse_selection_set(), 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_fragment_definition(): Fragment_Definition_Node
    {
        $start = $this->lexer->token;
        $this->expect_keyword('fragment');
        $name = $this->parse_fragment_name();
        // Experimental support for defining variables within fragments changes
        // the grammar of FragmentDefinition:
        //   - fragment FragmentName VariableDefinitions? on TypeCondition Directives? SelectionSet
        $variable_definitions = isset($this->lexer->options['experimentalFragmentVariables']) ? $this->parse_variable_definitions() : null;
        $this->expect_keyword('on');
        $type_condition = $this->parse_named_type();
        return new Fragment_Definition_Node(['name' => $name, 'variableDefinitions' => $variable_definitions, 'typeCondition' => $type_condition, 'directives' => $this->parse_directives(false), 'selectionSet' => $this->parse_selection_set(), 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_fragment_name(): Name_Node
    {
        if ($this->lexer->token->value === 'on') {
            throw $this->unexpected();
        }
        return $this->parse_name();
    }
    // Implements the parsing rules in the Values section.
    /**
     * Value[Const] :
     *   - [~Const] Variable
     *   - IntValue
     *   - FloatValue
     *   - StringValue
     *   - BooleanValue
     *   - NullValue
     *   - EnumValue
     *   - ListValue[?Const]
     *   - ObjectValue[?Const].
     *
     * BooleanValue : one of `true` `false`
     *
     * NullValue : `null`
     *
     * EnumValue : Name but not `true`, `false` or `null`
     *
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return BooleanValueNode|EnumValueNode|FloatValueNode|IntValueNode|StringValueNode|VariableNode|ListValueNode|ObjectValueNode|NullValueNode
     */
    private function parse_value_literal(bool $is_const): Value_Node
    {
        $token = $this->lexer->token;
        switch ($token->kind) {
            case Token::BRACKET_L:
                return $this->parse_array($is_const);
            case Token::BRACE_L:
                return $this->parse_object($is_const);
            case Token::INT:
                $this->lexer->advance();
                return new Int_Value_Node(['value' => $token->value, 'loc' => $this->loc($token)]);
            case Token::FLOAT:
                $this->lexer->advance();
                return new Float_Value_Node(['value' => $token->value, 'loc' => $this->loc($token)]);
            case Token::STRING:
            case Token::BLOCK_STRING:
                return $this->parse_string_literal();
            case Token::NAME:
                if ($token->value === 'true' || $token->value === 'false') {
                    $this->lexer->advance();
                    return new Boolean_Value_Node(['value' => $token->value === 'true', 'loc' => $this->loc($token)]);
                }
                if ($token->value === 'null') {
                    $this->lexer->advance();
                    return new Null_Value_Node(['loc' => $this->loc($token)]);
                }
                $this->lexer->advance();
                return new Enum_Value_Node(['value' => $token->value, 'loc' => $this->loc($token)]);
            case Token::DOLLAR:
                if (!$is_const) {
                    return $this->parse_variable();
                }
                break;
        }
        throw $this->unexpected();
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_string_literal(): String_Value_Node
    {
        $token = $this->lexer->token;
        $this->lexer->advance();
        return new String_Value_Node(['value' => $token->value, 'block' => $token->kind === Token::BLOCK_STRING, 'loc' => $this->loc($token)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_const_value(): Value_Node
    {
        return $this->parse_value_literal(true);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_variable_value(): Value_Node
    {
        return $this->parse_value_literal(false);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_array(bool $is_const): List_Value_Node
    {
        $start = $this->lexer->token;
        $parse_fn = $is_const ? fn(): Value_Node => $this->parse_const_value() : fn(): Value_Node => $this->parse_variable_value();
        return new List_Value_Node(['values' => $this->any(Token::BRACKET_L, $parse_fn, Token::BRACKET_R), 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_object(bool $is_const): Object_Value_Node
    {
        $start = $this->lexer->token;
        $this->expect(Token::BRACE_L);
        $fields = [];
        while (!$this->skip(Token::BRACE_R)) {
            $fields[] = $this->parse_object_field($is_const);
        }
        return new Object_Value_Node(['fields' => new Node_List($fields), 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_object_field(bool $is_const): Object_Field_Node
    {
        $start = $this->lexer->token;
        $name = $this->parse_name();
        $this->expect(Token::COLON);
        return new Object_Field_Node(['name' => $name, 'value' => $this->parse_value_literal($is_const), 'loc' => $this->loc($start)]);
    }
    // Implements the parsing rules in the Directives section.
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<DirectiveNode>
     */
    private function parse_directives(bool $is_const): Node_List
    {
        $directives = [];
        while ($this->peek(Token::AT)) {
            $directives[] = $this->parse_directive($is_const);
        }
        return new Node_List($directives);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_directive(bool $is_const): Directive_Node
    {
        $start = $this->lexer->token;
        $this->expect(Token::AT);
        return new Directive_Node(['name' => $this->parse_name(), 'arguments' => $this->parse_arguments($is_const), 'loc' => $this->loc($start)]);
    }
    // Implements the parsing rules in the Types section.
    /**
     * Handles the Type: TypeName, ListType, and NonNullType parsing rules.
     *
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return ListTypeNode|NamedTypeNode|NonNullTypeNode
     */
    private function parse_type_reference(): Type_Node
    {
        $start = $this->lexer->token;
        if ($this->skip(Token::BRACKET_L)) {
            $type = $this->parse_type_reference();
            $this->expect(Token::BRACKET_R);
            $type = new List_Type_Node(['type' => $type, 'loc' => $this->loc($start)]);
        } else {
            $type = $this->parse_named_type();
        }
        if ($this->skip(Token::BANG)) {
            return new Non_Null_Type_Node(['type' => $type, 'loc' => $this->loc($start)]);
        }
        return $type;
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_named_type(): Named_Type_Node
    {
        $start = $this->lexer->token;
        return new Named_Type_Node(['name' => $this->parse_name(), 'loc' => $this->loc($start)]);
    }
    // Implements the parsing rules in the Type Definition section.
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return TypeSystemDefinitionNode&Node
     */
    private function parse_type_system_definition(): Type_System_Definition_Node
    {
        // Many definitions begin with a description and require a lookahead.
        $keyword_token = $this->peek_description() ? $this->lexer->lookahead() : $this->lexer->token;
        if ($keyword_token->kind === Token::NAME) {
            switch ($keyword_token->value) {
                case 'schema':
                    return $this->parse_schema_definition();
                case 'scalar':
                    return $this->parse_scalar_type_definition();
                case 'type':
                    return $this->parse_object_type_definition();
                case 'interface':
                    return $this->parse_interface_type_definition();
                case 'union':
                    return $this->parse_union_type_definition();
                case 'enum':
                    return $this->parse_enum_type_definition();
                case 'input':
                    return $this->parse_input_object_type_definition();
                case 'directive':
                    return $this->parse_directive_definition();
            }
        }
        throw $this->unexpected($keyword_token);
    }
    private function peek_description(): bool
    {
        if ($this->peek(Token::STRING)) {
            return true;
        }
        return $this->peek(Token::BLOCK_STRING);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_description(): ?String_Value_Node
    {
        if ($this->peek_description()) {
            return $this->parse_string_literal();
        }
        return null;
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_schema_definition(): Schema_Definition_Node
    {
        $start = $this->lexer->token;
        $description = $this->parse_description();
        $this->expect_keyword('schema');
        $directives = $this->parse_directives(true);
        $operation_types = $this->many(Token::BRACE_L, fn(): Operation_Type_Definition_Node => $this->parse_operation_type_definition(), Token::BRACE_R);
        return new Schema_Definition_Node(['directives' => $directives, 'operationTypes' => $operation_types, 'loc' => $this->loc($start), 'description' => $description]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_operation_type_definition(): Operation_Type_Definition_Node
    {
        $start = $this->lexer->token;
        $operation = $this->parse_operation_type();
        $this->expect(Token::COLON);
        $type = $this->parse_named_type();
        return new Operation_Type_Definition_Node(['operation' => $operation, 'type' => $type, 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_scalar_type_definition(): Scalar_Type_Definition_Node
    {
        $start = $this->lexer->token;
        $description = $this->parse_description();
        $this->expect_keyword('scalar');
        $name = $this->parse_name();
        $directives = $this->parse_directives(true);
        return new Scalar_Type_Definition_Node(['name' => $name, 'directives' => $directives, 'loc' => $this->loc($start), 'description' => $description]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_object_type_definition(): Object_Type_Definition_Node
    {
        $start = $this->lexer->token;
        $description = $this->parse_description();
        $this->expect_keyword('type');
        $name = $this->parse_name();
        $interfaces = $this->parse_implements_interfaces();
        $directives = $this->parse_directives(true);
        $fields = $this->parse_fields_definition();
        return new Object_Type_Definition_Node(['name' => $name, 'interfaces' => $interfaces, 'directives' => $directives, 'fields' => $fields, 'loc' => $this->loc($start), 'description' => $description]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<NamedTypeNode>
     */
    private function parse_implements_interfaces(): Node_List
    {
        $types = [];
        if ($this->expect_optional_keyword('implements')) {
            // Optional leading ampersand
            $this->skip(Token::AMP);
            do {
                $types[] = $this->parse_named_type();
            } while ($this->skip(Token::AMP) || ($this->lexer->options['allowLegacySDLImplementsInterfaces'] ?? false) && $this->peek(Token::NAME));
        }
        return new Node_List($types);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<FieldDefinitionNode>
     */
    private function parse_fields_definition(): Node_List
    {
        // Legacy support for the SDL?
        if (($this->lexer->options['allowLegacySDLEmptyFields'] ?? false) && $this->peek(Token::BRACE_L) && $this->lexer->lookahead()->kind === Token::BRACE_R) {
            $this->lexer->advance();
            $this->lexer->advance();
            /** @phpstan-var NodeList<FieldDefinitionNode> $nodeList */
            return new Node_List([]);
        }
        /** @phpstan-var NodeList<FieldDefinitionNode> $nodeList */
        return $this->peek(Token::BRACE_L) ? $this->many(Token::BRACE_L, fn(): Field_Definition_Node => $this->parse_field_definition(), Token::BRACE_R) : new Node_List([]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_field_definition(): Field_Definition_Node
    {
        $start = $this->lexer->token;
        $description = $this->parse_description();
        $name = $this->parse_name();
        $args = $this->parse_arguments_definition();
        $this->expect(Token::COLON);
        $type = $this->parse_type_reference();
        $directives = $this->parse_directives(true);
        return new Field_Definition_Node(['name' => $name, 'arguments' => $args, 'type' => $type, 'directives' => $directives, 'loc' => $this->loc($start), 'description' => $description]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<InputValueDefinitionNode>
     */
    private function parse_arguments_definition(): Node_List
    {
        return $this->peek(Token::PAREN_L) ? $this->many(Token::PAREN_L, fn(): Input_Value_Definition_Node => $this->parse_input_value_definition(), Token::PAREN_R) : new Node_List([]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_input_value_definition(): Input_Value_Definition_Node
    {
        $start = $this->lexer->token;
        $description = $this->parse_description();
        $name = $this->parse_name();
        $this->expect(Token::COLON);
        $type = $this->parse_type_reference();
        $default_value = null;
        if ($this->skip(Token::EQUALS)) {
            $default_value = $this->parse_const_value();
        }
        $directives = $this->parse_directives(true);
        return new Input_Value_Definition_Node(['name' => $name, 'type' => $type, 'defaultValue' => $default_value, 'directives' => $directives, 'loc' => $this->loc($start), 'description' => $description]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_interface_type_definition(): Interface_Type_Definition_Node
    {
        $start = $this->lexer->token;
        $description = $this->parse_description();
        $this->expect_keyword('interface');
        $name = $this->parse_name();
        $interfaces = $this->parse_implements_interfaces();
        $directives = $this->parse_directives(true);
        $fields = $this->parse_fields_definition();
        return new Interface_Type_Definition_Node(['name' => $name, 'directives' => $directives, 'interfaces' => $interfaces, 'fields' => $fields, 'loc' => $this->loc($start), 'description' => $description]);
    }
    /**
     * UnionTypeDefinition :
     *   - Description? union Name Directives[Const]? UnionMemberTypes?
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_union_type_definition(): Union_Type_Definition_Node
    {
        $start = $this->lexer->token;
        $description = $this->parse_description();
        $this->expect_keyword('union');
        $name = $this->parse_name();
        $directives = $this->parse_directives(true);
        $types = $this->parse_union_member_types();
        return new Union_Type_Definition_Node(['name' => $name, 'directives' => $directives, 'types' => $types, 'loc' => $this->loc($start), 'description' => $description]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<NamedTypeNode>
     */
    private function parse_union_member_types(): Node_List
    {
        $types = [];
        if ($this->skip(Token::EQUALS)) {
            // Optional leading pipe
            $this->skip(Token::PIPE);
            do {
                $types[] = $this->parse_named_type();
            } while ($this->skip(Token::PIPE));
        }
        return new Node_List($types);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_enum_type_definition(): Enum_Type_Definition_Node
    {
        $start = $this->lexer->token;
        $description = $this->parse_description();
        $this->expect_keyword('enum');
        $name = $this->parse_name();
        $directives = $this->parse_directives(true);
        $values = $this->parse_enum_values_definition();
        return new Enum_Type_Definition_Node(['name' => $name, 'directives' => $directives, 'values' => $values, 'loc' => $this->loc($start), 'description' => $description]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<EnumValueDefinitionNode>
     */
    private function parse_enum_values_definition(): Node_List
    {
        return $this->peek(Token::BRACE_L) ? $this->many(Token::BRACE_L, fn(): Enum_Value_Definition_Node => $this->parse_enum_value_definition(), Token::BRACE_R) : new Node_List([]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_enum_value_definition(): Enum_Value_Definition_Node
    {
        $start = $this->lexer->token;
        $description = $this->parse_description();
        $name = $this->parse_name();
        $directives = $this->parse_directives(true);
        return new Enum_Value_Definition_Node(['name' => $name, 'directives' => $directives, 'loc' => $this->loc($start), 'description' => $description]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_input_object_type_definition(): Input_Object_Type_Definition_Node
    {
        $start = $this->lexer->token;
        $description = $this->parse_description();
        $this->expect_keyword('input');
        $name = $this->parse_name();
        $directives = $this->parse_directives(true);
        $fields = $this->parse_input_fields_definition();
        return new Input_Object_Type_Definition_Node(['name' => $name, 'directives' => $directives, 'fields' => $fields, 'loc' => $this->loc($start), 'description' => $description]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<InputValueDefinitionNode>
     */
    private function parse_input_fields_definition(): Node_List
    {
        return $this->peek(Token::BRACE_L) ? $this->many(Token::BRACE_L, fn(): Input_Value_Definition_Node => $this->parse_input_value_definition(), Token::BRACE_R) : new Node_List([]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return TypeSystemExtensionNode&Node
     */
    private function parse_type_system_extension(): Type_System_Extension_Node
    {
        $keyword_token = $this->lexer->lookahead();
        if ($keyword_token->kind === Token::NAME) {
            switch ($keyword_token->value) {
                case 'schema':
                    return $this->parse_schema_type_extension();
                case 'scalar':
                    return $this->parse_scalar_type_extension();
                case 'type':
                    return $this->parse_object_type_extension();
                case 'interface':
                    return $this->parse_interface_type_extension();
                case 'union':
                    return $this->parse_union_type_extension();
                case 'enum':
                    return $this->parse_enum_type_extension();
                case 'input':
                    return $this->parse_input_object_type_extension();
            }
        }
        throw $this->unexpected($keyword_token);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_schema_type_extension(): Schema_Extension_Node
    {
        $start = $this->lexer->token;
        $this->expect_keyword('extend');
        $this->expect_keyword('schema');
        $directives = $this->parse_directives(true);
        $operation_types = $this->peek(Token::BRACE_L) ? $this->many(Token::BRACE_L, fn(): Operation_Type_Definition_Node => $this->parse_operation_type_definition(), Token::BRACE_R) : new Node_List([]);
        if (count($directives) === 0 && count($operation_types) === 0) {
            $this->unexpected();
        }
        return new Schema_Extension_Node(['directives' => $directives, 'operationTypes' => $operation_types, 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_scalar_type_extension(): Scalar_Type_Extension_Node
    {
        $start = $this->lexer->token;
        $this->expect_keyword('extend');
        $this->expect_keyword('scalar');
        $name = $this->parse_name();
        $directives = $this->parse_directives(true);
        if (count($directives) === 0) {
            throw $this->unexpected();
        }
        return new Scalar_Type_Extension_Node(['name' => $name, 'directives' => $directives, 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_object_type_extension(): Object_Type_Extension_Node
    {
        $start = $this->lexer->token;
        $this->expect_keyword('extend');
        $this->expect_keyword('type');
        $name = $this->parse_name();
        $interfaces = $this->parse_implements_interfaces();
        $directives = $this->parse_directives(true);
        $fields = $this->parse_fields_definition();
        if (count($interfaces) === 0 && count($directives) === 0 && count($fields) === 0) {
            throw $this->unexpected();
        }
        return new Object_Type_Extension_Node(['name' => $name, 'interfaces' => $interfaces, 'directives' => $directives, 'fields' => $fields, 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_interface_type_extension(): Interface_Type_Extension_Node
    {
        $start = $this->lexer->token;
        $this->expect_keyword('extend');
        $this->expect_keyword('interface');
        $name = $this->parse_name();
        $interfaces = $this->parse_implements_interfaces();
        $directives = $this->parse_directives(true);
        $fields = $this->parse_fields_definition();
        if (count($interfaces) === 0 && count($directives) === 0 && count($fields) === 0) {
            throw $this->unexpected();
        }
        return new Interface_Type_Extension_Node(['name' => $name, 'directives' => $directives, 'interfaces' => $interfaces, 'fields' => $fields, 'loc' => $this->loc($start)]);
    }
    /**
     * UnionTypeExtension :
     *   - extend union Name Directives[Const]? UnionMemberTypes
     *   - extend union Name Directives[Const].
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_union_type_extension(): Union_Type_Extension_Node
    {
        $start = $this->lexer->token;
        $this->expect_keyword('extend');
        $this->expect_keyword('union');
        $name = $this->parse_name();
        $directives = $this->parse_directives(true);
        $types = $this->parse_union_member_types();
        if (count($directives) === 0 && count($types) === 0) {
            throw $this->unexpected();
        }
        return new Union_Type_Extension_Node(['name' => $name, 'directives' => $directives, 'types' => $types, 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_enum_type_extension(): Enum_Type_Extension_Node
    {
        $start = $this->lexer->token;
        $this->expect_keyword('extend');
        $this->expect_keyword('enum');
        $name = $this->parse_name();
        $directives = $this->parse_directives(true);
        $values = $this->parse_enum_values_definition();
        if (count($directives) === 0 && count($values) === 0) {
            throw $this->unexpected();
        }
        return new Enum_Type_Extension_Node(['name' => $name, 'directives' => $directives, 'values' => $values, 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_input_object_type_extension(): Input_Object_Type_Extension_Node
    {
        $start = $this->lexer->token;
        $this->expect_keyword('extend');
        $this->expect_keyword('input');
        $name = $this->parse_name();
        $directives = $this->parse_directives(true);
        $fields = $this->parse_input_fields_definition();
        if (count($directives) === 0 && count($fields) === 0) {
            throw $this->unexpected();
        }
        return new Input_Object_Type_Extension_Node(['name' => $name, 'directives' => $directives, 'fields' => $fields, 'loc' => $this->loc($start)]);
    }
    /**
     * DirectiveDefinition :
     *   - Description? directive @ Name ArgumentsDefinition? `repeatable`? on DirectiveLocations.
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_directive_definition(): Directive_Definition_Node
    {
        $start = $this->lexer->token;
        $description = $this->parse_description();
        $this->expect_keyword('directive');
        $this->expect(Token::AT);
        $name = $this->parse_name();
        $args = $this->parse_arguments_definition();
        $repeatable = $this->expect_optional_keyword('repeatable');
        $this->expect_keyword('on');
        $locations = $this->parse_directive_locations();
        return new Directive_Definition_Node(['name' => $name, 'description' => $description, 'arguments' => $args, 'repeatable' => $repeatable, 'locations' => $locations, 'loc' => $this->loc($start)]);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     *
     * @return NodeList<NameNode>
     */
    private function parse_directive_locations(): Node_List
    {
        // Optional leading pipe
        $this->skip(Token::PIPE);
        $locations = [];
        do {
            $locations[] = $this->parse_directive_location();
        } while ($this->skip(Token::PIPE));
        return new Node_List($locations);
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function parse_directive_location(): Name_Node
    {
        $start = $this->lexer->token;
        $name = $this->parse_name();
        if (Directive_Location::has($name->value)) {
            return $name;
        }
        throw $this->unexpected($start);
    }
}