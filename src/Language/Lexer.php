<?php

declare (strict_types=1);
namespace Graph_Ql\Language;

use Graph_Ql\Error\Syntax_Error;
use Graph_Ql\Utils\Utils;
/**
 * A lexer is a stateful stream generator, it returns the next token in the Source when advanced.
 * Assuming the source is valid, the final returned token will be EOF,
 * after which the lexer will repeatedly return the same EOF token whenever called.
 *
 * Algorithm is O(N) both on memory and time.
 *
 * @phpstan-import-type ParserOptions from Parser
 *
 * @see \GraphQL\Tests\Language\LexerTest
 */
class Lexer
{
    // https://spec.graphql.org/October2021/#sec-Punctuators
    private const TOKEN_BANG = 33;
    private const TOKEN_DOLLAR = 36;
    private const TOKEN_AMP = 38;
    private const TOKEN_PAREN_L = 40;
    private const TOKEN_PAREN_R = 41;
    private const TOKEN_DOT = 46;
    private const TOKEN_COLON = 58;
    private const TOKEN_EQUALS = 61;
    private const TOKEN_AT = 64;
    private const TOKEN_BRACKET_L = 91;
    private const TOKEN_BRACKET_R = 93;
    private const TOKEN_BRACE_L = 123;
    private const TOKEN_PIPE = 124;
    private const TOKEN_BRACE_R = 125;
    public Source $source;
    /** @phpstan-var ParserOptions */
    public array $options;
    /** The previously focused non-ignored token. */
    public Token $last_token;
    /** The currently focused non-ignored token. */
    public Token $token;
    /** The (1-indexed) line containing the current token. */
    public int $line = 1;
    /** The character offset at which the current line begins. */
    public int $line_start = 0;
    /** Current cursor position for UTF8 encoding of the source. */
    private int $position = 0;
    /** Current cursor position for ASCII representation of the source. */
    private int $byte_stream_position = 0;
    /** @phpstan-param ParserOptions $options */
    public function __construct(Source $source, array $options = [])
    {
        $start_of_file_token = new Token(Token::SOF, 0, 0, 0, 0);
        $this->source = $source;
        $this->options = $options;
        $this->last_token = $start_of_file_token;
        $this->token = $start_of_file_token;
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    public function advance(): Token
    {
        $this->last_token = $this->token;
        return $this->token = $this->lookahead();
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    public function lookahead(): Token
    {
        $token = $this->token;
        if ($token->kind !== Token::EOF) {
            do {
                $token = $token->next ?? $token->next = $this->read_token($token);
            } while ($token->kind === Token::COMMENT);
        }
        return $token;
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function read_token(Token $prev): Token
    {
        $body_length = $this->source->length;
        $this->position_after_whitespace();
        $position = $this->position;
        $line = $this->line;
        $col = 1 + $position - $this->line_start;
        if ($position >= $body_length) {
            return new Token(Token::EOF, $body_length, $body_length, $line, $col, $prev);
        }
        // Read next char and advance string cursor:
        [, $code, $bytes] = $this->read_char(true);
        switch ($code) {
            case self::TOKEN_BANG:
                // !
                return new Token(Token::BANG, $position, $position + 1, $line, $col, $prev);
            case 35:
                // #
                $this->move_string_cursor(-1, -1 * $bytes);
                return $this->read_comment($line, $col, $prev);
            case self::TOKEN_DOLLAR:
                // $
                return new Token(Token::DOLLAR, $position, $position + 1, $line, $col, $prev);
            case self::TOKEN_AMP:
                // &
                return new Token(Token::AMP, $position, $position + 1, $line, $col, $prev);
            case self::TOKEN_PAREN_L:
                // (
                return new Token(Token::PAREN_L, $position, $position + 1, $line, $col, $prev);
            case self::TOKEN_PAREN_R:
                // )
                return new Token(Token::PAREN_R, $position, $position + 1, $line, $col, $prev);
            case self::TOKEN_DOT:
                // .
                [, $char_code1] = $this->read_char(true);
                [, $char_code2] = $this->read_char(true);
                if ($char_code1 === self::TOKEN_DOT && $char_code2 === self::TOKEN_DOT) {
                    return new Token(Token::SPREAD, $position, $position + 3, $line, $col, $prev);
                }
                break;
            case self::TOKEN_COLON:
                // :
                return new Token(Token::COLON, $position, $position + 1, $line, $col, $prev);
            case self::TOKEN_EQUALS:
                // =
                return new Token(Token::EQUALS, $position, $position + 1, $line, $col, $prev);
            case self::TOKEN_AT:
                // @
                return new Token(Token::AT, $position, $position + 1, $line, $col, $prev);
            case self::TOKEN_BRACKET_L:
                // [
                return new Token(Token::BRACKET_L, $position, $position + 1, $line, $col, $prev);
            case self::TOKEN_BRACKET_R:
                // ]
                return new Token(Token::BRACKET_R, $position, $position + 1, $line, $col, $prev);
            case self::TOKEN_BRACE_L:
                // {
                return new Token(Token::BRACE_L, $position, $position + 1, $line, $col, $prev);
            case self::TOKEN_PIPE:
                // |
                return new Token(Token::PIPE, $position, $position + 1, $line, $col, $prev);
            case self::TOKEN_BRACE_R:
                // }
                return new Token(Token::BRACE_R, $position, $position + 1, $line, $col, $prev);
            // A-Z
            case 65:
            case 66:
            case 67:
            case 68:
            case 69:
            case 70:
            case 71:
            case 72:
            case 73:
            case 74:
            case 75:
            case 76:
            case 77:
            case 78:
            case 79:
            case 80:
            case 81:
            case 82:
            case 83:
            case 84:
            case 85:
            case 86:
            case 87:
            case 88:
            case 89:
            case 90:
            // _
            case 95:
            // a-z
            case 97:
            case 98:
            case 99:
            case 100:
            case 101:
            case 102:
            case 103:
            case 104:
            case 105:
            case 106:
            case 107:
            case 108:
            case 109:
            case 110:
            case 111:
            case 112:
            case 113:
            case 114:
            case 115:
            case 116:
            case 117:
            case 118:
            case 119:
            case 120:
            case 121:
            case 122:
                return $this->move_string_cursor(-1, -1 * $bytes)->read_name($line, $col, $prev);
            // -
            case 45:
            // 0-9
            case 48:
            case 49:
            case 50:
            case 51:
            case 52:
            case 53:
            case 54:
            case 55:
            case 56:
            case 57:
                return $this->move_string_cursor(-1, -1 * $bytes)->read_number($line, $col, $prev);
            // "
            case 34:
                [, $next_code] = $this->read_char();
                [, $next_next_code] = $this->move_string_cursor(1, 1)->read_char();
                if ($next_code === 34 && $next_next_code === 34) {
                    return $this->move_string_cursor(-2, -1 * $bytes - 1)->read_block_string($line, $col, $prev);
                }
                return $this->move_string_cursor(-2, -1 * $bytes - 1)->read_string($line, $col, $prev);
        }
        throw new Syntax_Error($this->source, $position, $this->unexpected_character_message($code));
    }
    /** @throws \JsonException */
    private function unexpected_character_message(?int $code): string
    {
        // SourceCharacter
        if ($code < 0x20 && $code !== 0x9 && $code !== 0xa && $code !== 0xd) {
            return 'Cannot contain the invalid character ' . Utils::print_char_code($code);
        }
        if ($code === 39) {
            return 'Unexpected single quote character (\'), did you mean to use a double quote (")?';
        }
        return 'Cannot parse the unexpected character ' . Utils::print_char_code($code) . '.';
    }
    /**
     * Reads an alphanumeric + underscore name from the source.
     *
     * [_A-Za-z][_0-9A-Za-z]*
     */
    private function read_name(int $line, int $col, Token $prev): Token
    {
        $start = $this->position;
        $body = $this->source->body;
        $length = strspn($body, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_', $this->byte_stream_position);
        $value = substr($body, $this->byte_stream_position, $length);
        $this->move_string_cursor($length, $length);
        return new Token(Token::NAME, $start, $this->position, $line, $col, $prev, $value);
    }
    /**
     * Reads a number token from the source file, either a float
     * or an int depending on whether a decimal point appears.
     *
     * Int:   -?(0|[1-9][0-9]*)
     * Float: -?(0|[1-9][0-9]*)(\.[0-9]+)?((E|e)(+|-)?[0-9]+)?
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function read_number(int $line, int $col, Token $prev): Token
    {
        $value = '';
        $start = $this->position;
        [$char, $code] = $this->read_char();
        $is_float = false;
        if ($code === 45) {
            // -
            $value .= $char;
            [$char, $code] = $this->move_string_cursor(1, 1)->read_char();
        }
        // guard against leading zero's
        if ($code === 48) {
            // 0
            $value .= $char;
            [$char, $code] = $this->move_string_cursor(1, 1)->read_char();
            if ($code >= 48 && $code <= 57) {
                throw new Syntax_Error($this->source, $this->position, 'Invalid number, unexpected digit after 0: ' . Utils::print_char_code($code));
            }
        } else {
            $value .= $this->read_digits();
            [$char, $code] = $this->read_char();
        }
        if ($code === 46) {
            // .
            $is_float = true;
            $this->move_string_cursor(1, 1);
            $value .= $char;
            $value .= $this->read_digits();
            [$char, $code] = $this->read_char();
        }
        if ($code === 69 || $code === 101) {
            // E e
            $is_float = true;
            $value .= $char;
            [$char, $code] = $this->move_string_cursor(1, 1)->read_char();
            if ($code === 43 || $code === 45) {
                // + -
                $value .= $char;
                $this->move_string_cursor(1, 1);
            }
            $value .= $this->read_digits();
        }
        return new Token($is_float ? Token::FLOAT : Token::INT, $start, $this->position, $line, $col, $prev, $value);
    }
    /**
     * Returns string with all digits + changes current string cursor position to point to the first char after digits.
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function read_digits(): string
    {
        [$char, $code] = $this->read_char();
        if ($code >= 48 && $code <= 57) {
            // 0 - 9
            $value = '';
            do {
                $value .= $char;
                [$char, $code] = $this->move_string_cursor(1, 1)->read_char();
            } while ($code >= 48 && $code <= 57);
            // 0 - 9
            return $value;
        }
        if ($this->position > $this->source->length - 1) {
            $code = null;
        }
        throw new Syntax_Error($this->source, $this->position, 'Invalid number, expected digit but got: ' . Utils::print_char_code($code));
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function read_string(int $line, int $col, Token $prev): Token
    {
        $start = $this->position;
        // Skip leading quote and read first string char:
        [$char, $code, $bytes] = $this->move_string_cursor(1, 1)->read_char();
        $chunk = '';
        $value = '';
        while (!in_array($code, [null, 10, 13], true)) {
            // not LineTerminator
            if ($code === 34) {
                // Closing Quote (")
                $value .= $chunk;
                // Skip quote
                $this->move_string_cursor(1, 1);
                return new Token(Token::STRING, $start, $this->position, $line, $col, $prev, $value);
            }
            $this->assert_valid_string_character_code($code, $this->position);
            $this->move_string_cursor(1, $bytes);
            if ($code === 92) {
                // \
                $value .= $chunk;
                [, $code] = $this->read_char(true);
                switch ($code) {
                    case 34:
                        $value .= '"';
                        break;
                    case 47:
                        $value .= '/';
                        break;
                    case 92:
                        $value .= '\\';
                        break;
                    case 98:
                        $value .= chr(8);
                        // \b (backspace)
                        break;
                    case 102:
                        $value .= "\f";
                        break;
                    case 110:
                        $value .= "\n";
                        break;
                    case 114:
                        $value .= "\r";
                        break;
                    case 116:
                        $value .= "\t";
                        break;
                    case 117:
                        $position = $this->position;
                        [$hex] = $this->read_chars(4);
                        if (preg_match('/[0-9a-fA-F]{4}/', $hex) !== 1) {
                            throw new Syntax_Error($this->source, $position - 1, "Invalid character escape sequence: \\u{$hex}");
                        }
                        $code = hexdec($hex);
                        assert(is_int($code), 'Since only a single char is read');
                        // UTF-16 surrogate pair detection and handling.
                        $high_order_byte = $code >> 8;
                        if ($high_order_byte >= 0xd8 && $high_order_byte <= 0xdf) {
                            [$utf16Continuation] = $this->read_chars(6);
                            if (preg_match('/^\\\\u[0-9a-fA-F]{4}$/', $utf16Continuation) !== 1) {
                                throw new Syntax_Error($this->source, $this->position - 5, 'Invalid UTF-16 trailing surrogate: ' . $utf16Continuation);
                            }
                            $surrogate_pair_hex = $hex . substr($utf16Continuation, 2, 4);
                            $value .= mb_convert_encoding(pack('H*', $surrogate_pair_hex), 'UTF-8', 'UTF-16');
                            break;
                        }
                        $this->assert_valid_string_character_code($code, $position - 2);
                        $value .= Utils::chr($code);
                        break;
                    // null means EOF, will delegate to general handling of unterminated strings
                    case null:
                        continue 2;
                    default:
                        $chr = Utils::chr($code);
                        throw new Syntax_Error($this->source, $this->position - 1, "Invalid character escape sequence: \\{$chr}");
                }
                $chunk = '';
            } else {
                $chunk .= $char;
            }
            [$char, $code, $bytes] = $this->read_char();
        }
        throw new Syntax_Error($this->source, $this->position, 'Unterminated string.');
    }
    /**
     * Reads a block string token from the source file.
     *
     * """("?"?(\\"""|\\(?!=""")|[^"\\]))*"""
     *
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function read_block_string(int $line, int $col, Token $prev): Token
    {
        $start = $this->position;
        // Skip leading quotes and read first string char:
        [$char, $code, $bytes] = $this->move_string_cursor(3, 3)->read_char();
        $chunk = '';
        $value = '';
        while ($code !== null) {
            // Closing Triple-Quote (""")
            if ($code === 34) {
                // Move 2 quotes
                [, $next_code] = $this->move_string_cursor(1, 1)->read_char();
                [, $next_next_code] = $this->move_string_cursor(1, 1)->read_char();
                if ($next_code === 34 && $next_next_code === 34) {
                    $value .= $chunk;
                    $this->move_string_cursor(1, 1);
                    return new Token(Token::BLOCK_STRING, $start, $this->position, $line, $col, $prev, Block_String::dedent_block_string_lines($value));
                }
                // move cursor back to before the first quote
                $this->move_string_cursor(-2, -2);
            }
            $this->assert_valid_block_string_character_code($code, $this->position);
            $this->move_string_cursor(1, $bytes);
            [, $next_code] = $this->read_char();
            [, $next_next_code] = $this->move_string_cursor(1, 1)->read_char();
            [, $next_next_next_code] = $this->move_string_cursor(1, 1)->read_char();
            // Escape Triple-Quote (\""")
            if ($code === 92 && $next_code === 34 && $next_next_code === 34 && $next_next_next_code === 34) {
                $this->move_string_cursor(1, 1);
                $value .= $chunk . '"""';
                $chunk = '';
            } else {
                // move cursor back to before the first quote
                $this->move_string_cursor(-2, -2);
                if ($code === 10) {
                    // new line
                    ++$this->line;
                    $this->line_start = $this->position;
                }
                $chunk .= $char;
            }
            [$char, $code, $bytes] = $this->read_char();
        }
        throw new Syntax_Error($this->source, $this->position, 'Unterminated string.');
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function assert_valid_string_character_code(int $code, int $position): void
    {
        // SourceCharacter
        if ($code < 0x20 && $code !== 0x9) {
            $char = Utils::print_char_code($code);
            throw new Syntax_Error($this->source, $position, "Invalid character within String: {$char}");
        }
    }
    /**
     * @throws \JsonException
     * @throws SyntaxError
     */
    private function assert_valid_block_string_character_code(int $code, int $position): void
    {
        // SourceCharacter
        if ($code < 0x20 && $code !== 0x9 && $code !== 0xa && $code !== 0xd) {
            $char = Utils::print_char_code($code);
            throw new Syntax_Error($this->source, $position, "Invalid character within String: {$char}");
        }
    }
    /**
     * Reads from body starting at startPosition until it finds a non-whitespace
     * or commented character, then places cursor to the position of that character.
     */
    private function position_after_whitespace(): void
    {
        while ($this->position < $this->source->length) {
            [, $code, $bytes] = $this->read_char();
            // Skip whitespace
            // tab | space | comma | BOM
            if (in_array($code, [9, 32, 44, 0xfeff], true)) {
                $this->move_string_cursor(1, $bytes);
            } elseif ($code === 10) {
                // new line
                $this->move_string_cursor(1, $bytes);
                ++$this->line;
                $this->line_start = $this->position;
            } elseif ($code === 13) {
                // carriage return
                [, $next_code, $next_bytes] = $this->move_string_cursor(1, $bytes)->read_char();
                if ($next_code === 10) {
                    // lf after cr
                    $this->move_string_cursor(1, $next_bytes);
                }
                ++$this->line;
                $this->line_start = $this->position;
            } else {
                break;
            }
        }
    }
    /**
     * Reads a comment token from the source file.
     *
     * #[\u0009\u0020-\uFFFF]*
     */
    private function read_comment(int $line, int $col, Token $prev): Token
    {
        $start = $this->position;
        $value = '';
        $bytes = 1;
        do {
            [$char, $code, $bytes] = $this->move_string_cursor(1, $bytes)->read_char();
            $value .= $char;
        } while ($code !== null && ($code > 0x1f || $code === 0x9));
        return new Token(Token::COMMENT, $start, $this->position, $line, $col, $prev, $value);
    }
    /**
     * Reads next UTF8Character from the byte stream, starting from $byteStreamPosition.
     *
     * @return array{string, int|null, int}
     */
    private function read_char(bool $advance = false, ?int $byte_stream_position = null): array
    {
        if ($byte_stream_position === null) {
            $byte_stream_position = $this->byte_stream_position;
        }
        $code = null;
        $utf8char = '';
        $bytes = 0;
        $position_offset = 0;
        if (isset($this->source->body[$byte_stream_position])) {
            $ord = ord($this->source->body[$byte_stream_position]);
            if ($ord < 128) {
                $bytes = 1;
            } elseif ($ord < 224) {
                $bytes = 2;
            } elseif ($ord < 240) {
                $bytes = 3;
            } else {
                $bytes = 4;
            }
            for ($pos = $byte_stream_position; $pos < $byte_stream_position + $bytes; ++$pos) {
                $utf8char .= $this->source->body[$pos];
            }
            $position_offset = 1;
            $code = $bytes === 1 ? $ord : Utils::ord($utf8char);
        }
        if ($advance) {
            $this->move_string_cursor($position_offset, $bytes);
        }
        return [$utf8char, $code, $bytes];
    }
    /**
     * Reads next $numberOfChars UTF8 characters from the byte stream.
     *
     * @return array{string, int}
     */
    private function read_chars(int $char_count): array
    {
        $result = '';
        $total_bytes = 0;
        $byte_offset = $this->byte_stream_position;
        for ($i = 0; $i < $char_count; ++$i) {
            [$char, $code, $bytes] = $this->read_char(false, $byte_offset);
            $total_bytes += $bytes;
            $byte_offset += $bytes;
            $result .= $char;
        }
        $this->move_string_cursor($char_count, $total_bytes);
        return [$result, $total_bytes];
    }
    /** Moves internal string cursor position. */
    private function move_string_cursor(int $position_offset, int $byte_stream_offset): self
    {
        $this->position += $position_offset;
        $this->byte_stream_position += $byte_stream_offset;
        return $this;
    }
}