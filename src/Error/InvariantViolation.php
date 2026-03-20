<?php

declare (strict_types=1);
namespace Graph_Ql\Error;

/**
 * Note:
 * This exception should not inherit base Error exception as it is raised when there is an error somewhere in
 * user-land code.
 */
class Invariant_Violation extends \LogicException
{
}