<?php

declare (strict_types=1);
namespace Graph_Ql\Error;

use Graph_Ql\Utils\Utils;
/**
 * @phpstan-type InputPath list<string|int>
 */
class Coercion_Error extends Error
{
    /** @var InputPath|null */
    public ?array $input_path = null;
    /** @var mixed whatever invalid value was passed */
    public $invalid_value;
    /**
     * @param InputPath|null $inputPath
     * @param mixed $invalidValue whatever invalid value was passed
     *
     * @return static
     */
    public static function make(string $message, ?array $input_path, $invalid_value, ?\Throwable $previous = null): self
    {
        $instance = new static($message, null, null, [], null, $previous);
        $instance->input_path = $input_path;
        $instance->invalid_value = $invalid_value;
        return $instance;
    }
    public function print_input_path(): ?string
    {
        if ($this->input_path === null) {
            return null;
        }
        $path = '';
        foreach ($this->input_path as $segment) {
            $path .= is_int($segment) ? "[{$segment}]" : ".{$segment}";
        }
        return $path;
    }
    public function print_invalid_value(): string
    {
        return Utils::print_safe_json($this->invalid_value);
    }
}