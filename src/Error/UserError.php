<?php

declare (strict_types=1);
namespace Graph_Ql\Error;

/**
 * Caused by GraphQL clients and can safely be displayed.
 */
class User_Error extends \RuntimeException implements Client_Aware
{
    public function is_client_safe(): bool
    {
        return true;
    }
}