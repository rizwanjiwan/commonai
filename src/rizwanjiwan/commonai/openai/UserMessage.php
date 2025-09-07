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

    /**
     * @var Tool[] array of tools available
     */
    private array $tools=array();   //tools available

    private bool $hasSent=false;

    private array $additionalInputs=array();//internal used only for tools

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

    public function provideTool(Tool $tool):self
    {
        $this->tools[$tool->getName()]=$tool;
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
            array_push($input,array(
                                            'role'=>'developer',
                                            'content'=>$contentArray
                )
            );
        }
        //user input:
        $contentArray=array();
        array_push($contentArray,array("type"=>"input_text","text"=>$this->prompt));
        //file input
        foreach($this->files as $file){
            array_push($contentArray,array("type"=>"input_file","file_id"=>$file->id));
        }
        array_push($input,array(
                                        'role'=>'user',
                                        'content'=>$contentArray
                                    )
        );

        //prep request:
        $chatRequest= array(
            'model'=>$this->model,
            'input'=>$input,
            'store'=>true,
            'tools'=>array()
        );
        //add tools available
        foreach($this->tools as $tool){
            array_push($chatRequest['tools'],$this->toolToDefinition($tool));
        }

        if($this->messageResponseId!==null){
            $chatRequest['previous_response_id']=$this->messageResponseId;
        }
        try{
            $this->hasSent=true;
            $toolsCalled=false;
            $response=null;
            do{ //loop to call tools if needed
                $response=$this->client->responses()->create($chatRequest);
                foreach($response->output as $item){
                    if($item->type==='function_call'){
                        $toolsCalled=true;
                        $itemArray=$item->toArray();
                        $itemArray['call_id']=$item->callId;    //hack because toArray doesn't include call_id just callId (bug upstream?)
                        array_push($chatRequest['input'],$itemArray);//add function call item in for the next request
                        $this->log->debug('Calling tool: '.$item->name);
                        $tool=$this->tools[$item->name];
                        $result=$tool->execute(json_decode($item->arguments,true));
                        //append to input
                        array_push($chatRequest['input'],
                            array(
                                'type'=>'function_call_output',
                                'call_id'=> $item->callId,
                                'output'=>json_encode($result)
                            )
                        );
                    }
                }
                if($toolsCalled) {
                    $this->log->debug('Calling with tools...');
                    $response = $this->client->responses()->create($chatRequest);
                }
                else{
                    $this->log->debug('No tools called');
                }
            }while($toolsCalled && empty($response->outputText));
            return new MessageResponse($response);

        }
        catch(\Exception $e){
            $this->log->error($e->getMessage()." ".$e->getTraceAsString());
            throw new ApiException('Failed to send message: ' . $e->getMessage(), 0, $e);
        }
    }

    private function toolToDefinition(Tool $tool): ?array
    {
        //build parameters first
        $parameters=array(
            'type'=>'object',
            'properties'=>array()
        );
        $required=array();
        foreach($tool->getParameters() as $parameter){
            $paramArray=array();
            $paramArray['type']=$parameter->type;
            $paramArray['name']=$parameter->name;
            $paramArray['description']=$parameter->description;
            if($parameter->enum!==null){
                $paramArray['enum']=$parameter->enum;
            }
            array_push($required,$parameter->name); //every parameter is required because we use strict mode
            $parameters['properties'][$parameter->name]=$paramArray;
        }
        $parameters['required']=$required;
        $parameters['additionalProperties']=false;

        //now full function dfn
        $toolArray=array(
            'type'=>'function',
            'name'=>$tool->getName(),
            'description'=>$tool->getDescription(),
            'strict'=>true,
            'parameters'=>$parameters
        );

        return $toolArray;
    }

/***
 * $tools = [
 *      [
 *          'type' => 'function',
 *          'function' => [
 *              'name' => 'get_current_weather',
 *              'description' => 'Get the current weather in a given location',
 *              'parameters' => [
 *                  'type' => 'object',
 *                  'properties' => [
 *                      'location' => [
 *                          'type' => 'string',
 *                          'description' => 'The city and country, e.g. Toronto, Canada'
 *                      ],
 *                      'unit' => [
 *                          'type' => 'string',
 *                          'enum' => ['celsius', 'fahrenheit'],
 *                          'description' => 'The unit for temperature'
 *                      ]
 *                  ],
 *                  'required' => ['location']
 *              ]
 *          ]
 *      ]
 * ];
 * @return array
 */
}