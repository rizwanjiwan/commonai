<?php

namespace rizwanjiwan\commonai\openai;

use OpenAI\Responses\Files\CreateResponse;

class File extends AbstractResponse
{
    public string $id;


    public function __construct(CreateResponse $response)
    {
        parent::__construct($response->id,$response->object,$response->createdAt);
    }
}