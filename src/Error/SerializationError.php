<?php

declare (strict_types=1);
namespace Graph_Ql\Error;

/**
 * Thrown when failing to serialize a leaf value.
 *
 * Not generally safe for clients, as the wrong given value could
 * be something not intended to ever be seen by clients.
 */
class Serialization_Error extends \Exception
{
}