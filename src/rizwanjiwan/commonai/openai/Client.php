<?php

namespace rizwanjiwan\commonai\openai;

use Exception;
use Monolog\Logger;
use OpenAI;
use OpenAI\Responses\Assistants\AssistantResponse;
use rizwanjiwan\common\classes\LogManager;


class Client
{
    private OpenAI\Client $client;

    private Logger $log;

    private string $model;

    //Two assistants depending on what the user wants to do (with a file or without a file)
    private ?Assistantresponse $toollessAssistant = null;


    public function __construct(string $apiKey, string $model)
    {
        $this->log = LogManager::createLogger('OpenAiClient');
        $this->log->debug('Creating OpenAI client with API key and model: ' . $model);
        $this->client = OpenAI::client($apiKey);
        $this->model = $model;
    }

    /**
     * Upload file to reference later
     * @param string $fileName the name and path to the file
     * @return File the uploaded file details
     */
    public function uploadFile(string $fileName): File
    {
        $this->log->debug('Uploading file '.$fileName.' to OpenAI');
        $response=$this->client->files()->upload([
            'file' => fopen($fileName, 'r'),
            'purpose' => 'user_data',
        ]);
        $file=new File($response);
        $this->log->debug('Uploaded file '.$file->id.' to OpenAI');
        return $file;
    }

    public function deleteFile(File $file):self
    {
        $this->log->debug('Uploading file '.$file->id.' to OpenAI');
        $this->client->files()->delete($file->id);
        return $this;
    }
    public function createUserMessage(?string $previousResponseId=null):UserMessage
    {
        return new UserMessage($this->client,$this->model,$previousResponseId);
    }

}