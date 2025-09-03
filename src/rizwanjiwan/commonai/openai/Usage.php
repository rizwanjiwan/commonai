<?php

namespace rizwanjiwan\commonai\openai;

class Usage
{
    public function __construct(public int $promptTokens, public int $completionTokens, public int $totalTokens)
    {}
}