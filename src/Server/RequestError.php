<?php

declare (strict_types=1);
namespace Graph_Ql\Server;

use Graph_Ql\Error\Client_Aware;
class Request_Error extends \Exception implements Client_Aware
{
    public function is_client_safe(): bool
    {
        return true;
    }
}