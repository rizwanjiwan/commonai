<?php

namespace rizwanjiwan\commonai\openai;

use Monolog\Logger;
use OpenAI\Responses\Files\CreateResponse;
use rizwanjiwan\common\classes\LogManager;

class FileResponse extends AbstractResponse
{
    /**
     * @var int Size of the uploaded file in bytes.
     */
    public int $bytes;

    /**
     * @var string Filename of the uploaded file.
     */
    public string $fileName;
    /**
     * @var string|null Fingerprint of the system that generated the response, if available.
     * /**
     * @var Logger Logger instance for logging debug information.
     */
    private Logger $log;

    public function __construct(CreateResponse $response)
    {
        $this->log = LogManager::createLogger('OpenAiResponse');
        $respArray = $response->toArray();
        $this->log->debug('Got response: ' . json_encode($respArray));
        parent::__construct($respArray['id'], $respArray['object'], $respArray['created_at']);
        $this->bytes = $respArray['bytes'];
        $this->fileName = $respArray['filename'];
    }
}