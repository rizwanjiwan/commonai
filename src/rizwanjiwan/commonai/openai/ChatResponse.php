<?php

namespace rizwanjiwan\commonai\openai;

use Monolog\Logger;
use OpenAI\Responses\Chat\CreateResponse;
use rizwanjiwan\common\classes\LogManager;


class ChatResponse extends AbstractResponse
{

    /**
     * @var string Model used to generate the response, e.g., "gpt-3.5-turbo".
     */
    public string $model;
    /**
     * @var string|null Fingerprint of the system that generated the response, if available.
     */
    public ?string $fingerprint;

    /**
     * @var Choice[]
     */
    public array $choices = [];

    /**
     * @var Logger Logger instance for logging debug information.
     */
    private Logger $log;
    /**
     * @var Usage Usage statistics for the response, including token counts.
     */
    private Usage $usage;

    public function __construct(CreateResponse $response)
    {
        $this->log= LogManager::createLogger('OpenAiChatResponse');
        $respArray=$response->toArray();
        $this->log->debug('Got response: '.json_encode($respArray));
        parent::__construct($respArray['id'],$respArray['object'],$respArray['created']);
        $this->model = $respArray['model'];
        $this->fingerprint = array_key_exists('system_fingerprint',$respArray)?$respArray['system_fingerprint']:null;
        foreach($respArray['choices'] as $choice) {
            array_push($this->choices, Choice::fromArray($choice));
        }
        $this->usage=new Usage($respArray['usage']['prompt_tokens'],$respArray['usage']['completion_tokens'],$respArray['usage']['total_tokens']);
    }

}