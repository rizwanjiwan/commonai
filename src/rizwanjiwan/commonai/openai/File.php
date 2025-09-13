<?php

namespace rizwanjiwan\commonai\openai;

use OpenAI\Responses\Files\CreateResponse;
use OpenAI\Responses\Files\RetrieveResponse;

class File extends AbstractResponse
{
    public string $fileName;

    public function __construct(CreateResponse|RetrieveResponse $response)
    {
        parent::__construct($response->id,$response->object,$response->createdAt);
        $this->fileName=$response->filename;
    }
}