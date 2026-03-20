<?php

declare (strict_types=1);
namespace Graph_Ql\Error;

/**
 * Implementing HasExtensions allows this error to provide additional data to clients.
 */
interface Provides_Extensions
{
    /**
     * Data to include within the "extensions" key of the formatted error.
     *
     * @return array<string, mixed>|null
     */
    public function get_extensions(): ?array;
}