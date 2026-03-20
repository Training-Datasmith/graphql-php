<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

#[\Attribute(\Attribute::TARGET_ALL)]
class Description
{
    public string $description;
    public function __construct(string $description)
    {
        $this->description = $description;
    }
}