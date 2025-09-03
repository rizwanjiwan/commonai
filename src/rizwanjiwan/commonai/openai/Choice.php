<?php

namespace rizwanjiwan\commonai\openai;

class Choice
{
    public int $index;
    public string $role;
    public string $content;

    public string $finishReason;

    public static function fromArray(array $data): Choice
    {
        //todo
        return new Choice();
    }

}