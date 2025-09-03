<?php

namespace rizwanjiwan\commonai\openai;

use Exception;
use Monolog\Logger;
use OpenAI;
use OpenAI\Responses\Assistants\AssistantResponse;
use OpenAI\Responses\Threads\ThreadResponse;
use rizwanjiwan\common\classes\LogManager;
use RuntimeException;


class Client
{
    private OpenAI\Client $client;

    private Logger $log;
    private ?int $threadId=null;

    private ?string $instructions=null;

    private string $model;

    //Two assistants depending on what the user wants to do (with a file or without a file)
    private ?Assistantresponse $toollessAssistant=null;
    private AssistantResponse $fileToolAssistant;

    private Files $files;   //files to clean up after the chat

    public function __construct(string $apiKey,string $model)
    {
        $this->log=LogManager::createLogger('OpenAiClient');
        $this->log->debug('Creating OpenAI client with API key and model: '.$model);
        $this->client= OpenAI::client($apiKey);
        $this->model=$model;
        $this->fileToolAssistant=$this->client->assistants()->create([
            'name' => 'file-assistant',
            'model' => $this->model,
            'tools' => [
                ['type' => 'file_search'], // retrieval over uploaded files
            ]
        ]);
        $this->files=new Files();
    }

    /**
     * Continue conversing with an existing thread
     * @param int $threadId
     * @return $this
     */
    public function continueThread(int $threadId):self
    {
        $this->threadId=$threadId;
        return $this;
    }

    public function setInstructions(string $instructions):self
    {
        $this->instructions=$instructions;
        return $this;
    }

    private function createToollessAssistant():void
    {
        if($this->toollessAssistant===null) {
            $params = [
                'name' => 'toolless-assistant',
                'model' => $this->model,
            ];
            if ($this->instructions !== null) {
                $params['instructions'] = $this->instructions;
            }
            $this->toollessAssistant = $this->client->assistants()->create($params);
        }
    }

    /**
     * Upload files to reference later
     * @param array $fileNames
     * @return Files The files uploaded which you can pass into sendMessage()
     */
    public function uploadFiles(array $fileNames): Files
    {
        $this->log->debug('Uploading files to OpenAI');
        $files=new Files();
        $uploadedFiles = [];
        foreach ($fileNames as $file) {
            $this->log->debug('Uploading file: '.$file);
            $response = $this->client->files()->upload([
                'file' => fopen($file, 'r'),
                'purpose' => 'assistants',
            ]);
            $fileResponse=new FileResponse($response);
            $files->addFile($fileResponse);
            $this->files->addFile($fileResponse);//save to clean up later
        }
        return $files;
    }

    /**
     * Send a message and note any files you're including that you reference in your prompt
     * @param string $prompt
     * @param Files|null $files
     * @return string
     */
    public function sendMessage(string $prompt,?Files $files=null): string
    {
        $this->log->debug('Chat with prompt: '.$prompt);
        $this->createToollessAssistant();   //create if not already created
        $msgReq= array(
            'role'      => 'user',
            'content'   => $prompt,
        );
        $attachments=array();
        if($files!=null) {
            //we're sending a message with files
            $this->log->debug('Sending prompt with files...');
            //build attachments
            foreach($files->responses as $file){
                array_push($attachments,['tools'=>array(array('type'=>'file_search')),'file_id'=>$file->id]);
            }
            $msgReq['attachments']=$attachments;
        }
        $this->log->debug('Creating message...');
        if($this->threadId===null){//create a thread if we don't have one
                $this->threadId=$this->client->threads()->create()->id;
        }
        $this->client->threads()->messages()->create($this->threadId,$msgReq);
        $this->log->debug('Starting run...');
        $run=$this->client->threads()->runs()->create($this->threadId,[
            'assistant_id' => $files==null?$this->toollessAssistant->id:$this->fileToolAssistant->id,
            'model' => $this->model,
        ]);
        $this->log->debug('Waiting for run to complete...');
        while(true){    //poll and wait
            $run = $this->client->threads()->runs()->retrieve($this->thread->id,$run->id);
            if (in_array($run->status, ['completed', 'failed', 'cancelled', 'expired'])) {
                break;
            }
            usleep(800 * 1000); // 0.8s
        }
        $this->log->debug('Run completed');
        if ($run->status !== 'completed') {
            $this->log->error('Run did not complete: '.$run->status);
            throw new RuntimeException("Run did not complete: {$run->status}");
        }

        // 6) Read the latest assistant message in the thread
        $msgs = $this->client->threads()->messages()->list($this->threadId);

        // Find the newest assistant message
        $assistantText = '';
        foreach ($msgs->data as $m) {
            if ($m->role === 'assistant') {
                // Messages can include multiple content parts (text, citations, images). We collect the text parts.
                foreach ($m->content as $part) {
                    if (isset($part->type) && $part->type === 'text') {
                        $assistantText .= $part->text->value . "\n";
                    }
                }
                return trim($assistantText);
            }
        }
        return 'An error has occurred with OpenAI. No assistant message found.';
    }

    public function getThreadId(): string
    {
        return $this->threadId;
    }
    public function __destruct()
    {
        foreach($this->files->responses as $file) {
            try{
                $this->client->files()->delete($file->id);
            }
            catch(Exception $e) {
                $this->log->error('Error deleting file: '.$file->fileName.' - '.$e->getMessage());
            }
        }
    }
}