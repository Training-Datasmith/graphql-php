<?php

declare (strict_types=1);
namespace Graph_Ql\Error;

/**
 * Implementing ClientAware allows graphql-php to decide if this error is safe to be shown to clients.
 *
 * Only errors that both implement this interface and return true from `isClientSafe()`
 * will retain their original error message during formatting.
 *
 * All other errors will have their message replaced with "Internal server error".
 */
interface Client_Aware
{
    /**
     * Is it safe to show the error message to clients?
     *
     * @api
     */
    public function is_client_safe(): bool;
}