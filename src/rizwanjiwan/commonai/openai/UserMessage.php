<?php

namespace rizwanjiwan\commonai\openai;

use Monolog\Logger;

/**
 * Represents a message from the user to the open AI model for inference.
 */
class UserMessage
{
    private Logger $log;
    private \OpenAI\Client $client;
    private string $model;
    private ?string $prompt=null;
    private ?string $instructions=null;
    private ?string $messageResponseId;

    private bool $hasSent=false;

    /**
     * @var File[] of files to send along with the message
     */
    private array $files=array(); //File to send along with the message

    public function __construct(\OpenAI\Client $client, string $model, ?string $messageResponseId=null){
        $this->client=$client;
        $this->model=$model;
        $this->messageResponseId=$messageResponseId;
        $this->log=new Logger("UserMessage");
    }

    public function setPrompt(string $prompt):self
    {
        $this->log->debug('Setting prompt: '.$prompt);
        $this->prompt=$prompt;
        return $this;
    }
    public function includeFile(File $file):self
    {
        $this->log->debug('Adding file to message: '.$file->id);
        array_push($this->files,$file);
        return $this;
    }

    public function setInstructions(string $instructions):self
    {
        $this->log->debug('Setting instructions: '.$instructions);
        $this->instructions=$instructions;
        return $this;
    }
    /**
     * You can only send a message once. If you need to send another message, create a new UserMessage instance from Client.
     * @throws ApiException if you've already sent this message or forgot to set a prompt
     */
    public function send():MessageResponse
    {
        if($this->hasSent){
            $this->log->error('Attempted to resend message');
            throw new ApiException("Message has already been sent");
        }
        if($this->prompt===null){
            $this->log->error('Prompt not set before sending');
            throw new ApiException("You must provide a prompt");
        }
        $this->log->debug('Sending message');
        $input=array();
        //developer input:
        if($this->instructions!==null){
            $contentArray=array();
            array_push($contentArray,array("type"=>"input_text","text"=>$this->instructions));
            array_push($input,$contentArray);
        }
        //user input:
        $contentArray=array();
        array_push($contentArray,array("type"=>"input_text","text"=>$this->prompt));
        foreach($this->files as $file){
            array_push($contentArray,array("type"=>"input_file","file_id"=>$file->id));
        }
        array_push($input,$contentArray);

        $chatRequest= array(
            'model'=>$this->model,
            'input'=>$input,
            'store'=>true
        );
        if($this->messageResponseId!==null){
            $chatRequest['previous_response_id']=$this->messageResponseId;
        }
        try{
            $this->hasSent=true;
            $response=$this->client->responses()->create($chatRequest);
            return new MessageResponse($response);
        }
        catch(\Exception $e){
            $this->log->error($e->getMessage()." ".$e->getTraceAsString());
            throw new ApiException('Failed to send message: ' . $e->getMessage(), 0, $e);
        }
    }
}