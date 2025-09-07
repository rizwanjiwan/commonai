<?php

namespace rizwanjiwan\commonai\openai;

/**
 * Defines a tool that can be used in a chat completion.
 */
interface Tool
{
    /**
     * Execute the tool with the given arguments.
     * @param array $parameters
     * @return array
     */
    public function execute(array $parameters): array;

    /**
     * Get the name of the tool.
     * @return string
     */
    public function getName():string;
    /**
     * Get the description of the tool.
     * @return string
     */
    public function getDescription():string;

    /**
     * Define the function parameters (which will be passed to 'execute')
     * @return ToolParameter[] of parameters for this tool
     */
    public function getParameters():array;

}