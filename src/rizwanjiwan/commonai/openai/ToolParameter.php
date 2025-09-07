<?php

namespace rizwanjiwan\commonai\openai;
/**
 * Defines a parameter for a tool.
 */
class ToolParameter
{
    public string $name;
    public array $type;
    public string $description;
    public ?array $enum = null;
    public bool $isRequired=false;

    public function __construct(string $name, array $type, string $description)
    {
        $this->name = $name;
        $this->type = $type;
        $this->description = $description;
    }

    public function setEnum(array $enum): self
    {
        $this->enum = $enum;
        return $this;
    }
    public function setIsRequired(bool $isRequired): self
    {
        $this->isRequired = $isRequired;
        return $this;
    }


}