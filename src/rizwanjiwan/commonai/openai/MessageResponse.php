<?php

namespace rizwanjiwan\commonai\openai;

use OpenAI\Responses\Responses\CreateResponse;

class MessageResponse extends AbstractResponse
{

    public string $message;

    /**
     * @throws ApiException
     */
    public function __construct(CreateResponse $response)
    {
        parent::__construct($response->id, $response->object, $response->createdAt);
        $this->log->debug($response->outputText);
        if($this->object!=='response'){
            throw new ApiException("Invalid response object type: ".$this->object);
        }
        $this->message=$response->outputText;
    }
}