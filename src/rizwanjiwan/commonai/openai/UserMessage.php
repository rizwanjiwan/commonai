<?php

namespace rizwanjiwan\commonai\openai;

use Monolog\Logger;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ServerException;
use OpenAI\Responses\Responses\CreateResponse;
use rizwanjiwan\common\classes\LogManager;

/**
 * Represents a message from the user to the open AI model for inference.
 */
class UserMessage
{
    private const DEFAULT_RETRY_DELAY_SECONDS = 1;
    private const MAX_RETRY_DELAY_SECONDS = 2;

    private Logger $log;
    private ClientContract $client;
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
     * @var string[] of file IDs to send along with the message
     */
    private array $files=array(); //File to send along with the message

    public function __construct(ClientContract $client, string $model, ?string $messageResponseId=null){
        $this->client=$client;
        $this->model=$model;
        $this->messageResponseId=$messageResponseId;
        $this->log=LogManager::createLogger("UserMessage");
    }

    public function setPrompt(string $prompt):self
    {
        $this->log->debug('Setting prompt: '.$prompt);
        $this->prompt=$prompt;
        return $this;
    }
    public function includeFile(string $fileId):self
    {
        $this->log->debug('Adding file to message: '.$fileId);
        array_push($this->files,$fileId);
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
     * Send the prompt and return the model's reply after executing any requested tools.
     * Each UserMessage can be sent once. A brief 503 retries only the affected API request.
     *
     * @return MessageResponse The model's final reply.
     * @throws ApiException If the message was already sent, the prompt is missing, or processing fails.
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
        foreach($this->files as $fileId){
            array_push($contentArray,array("type"=>"input_file","file_id"=>$fileId));
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
            //$this->log->debug('Making initial API call: '.json_encode($chatRequest));
            $response=$this->createResponse($chatRequest, 'initial request');
            do{ //loop to call tools if needed
                $toolsCalled=false;
                //$this->log->debug(json_encode($response));
                foreach($response->output as $item){
                    if($item->type==='reasoning'){
                        // Response output items contain fields such as status that are not
                        // accepted when the item is sent back as request input.
                        $itemArray=$item->toArray();
                        unset($itemArray['status']);
                        array_push($chatRequest['input'],$itemArray);

                    }
                    else if($item->type==='function_call'){
                        $toolsCalled=true;
                        $itemArray=$item->toArray();
                        // The function call status describes the completed response item;
                        // it is not part of the function call input schema.
                        unset($itemArray['status']);
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
                    $response = $this->createResponse($chatRequest, 'tool follow-up');
                }
                else{
                    $this->log->debug('No tools called');
                }
            }while($toolsCalled && empty($response->outputText));
            //should have a message from the model now
            return new MessageResponse($response);
        }
        catch(\Exception $e){
            $this->log->error($e->getMessage()." ".$e->getTraceAsString());
            throw new ApiException('Failed to send message: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Retry a single Responses API request once if the service is briefly unavailable.
     * On a follow-up, the payload already contains the tool results. Reuse it as sent.
     *
     * @param array<string, mixed> $chatRequest The complete payload for this API call.
     * @param string $stage Identifies the initial request or tool follow-up in the log.
     * @return CreateResponse The successful response for this payload.
     * @throws \Exception If the SDK fails and a retry is unavailable or unsuccessful.
     */
    private function createResponse(array $chatRequest, string $stage): CreateResponse
    {
        try {
            return $this->client->responses()->create($chatRequest);
        } catch (ServerException $e) {
            if ($e->response->getStatusCode() !== 503) {
                throw $e;
            }

            // Retry-After can be a number of seconds or an HTTP date.
            $retryAfter = trim($e->response->getHeaderLine('Retry-After'));
            $delay = self::DEFAULT_RETRY_DELAY_SECONDS;
            if (ctype_digit($retryAfter)) {
                $delay = (int)$retryAfter;
            } elseif ($retryAfter !== '') {
                $retryAt = strtotime($retryAfter);
                if ($retryAt !== false) {
                    $delay = max(0, $retryAt - time());
                }
            }

            $requestId = $e->response->getHeaderLine('x-request-id');
            $details = 'OpenAI returned HTTP 503 for ' . $stage . ' on model ' . $this->model
                . ($requestId === '' ? '' : ' (request ID ' . $requestId . ')');
            if ($delay > self::MAX_RETRY_DELAY_SECONDS) {
                $this->log->warning($details . '. Retry-After exceeds the wait limit.');
                throw $e;
            }
            $this->log->warning($details . '. Retrying once after ' . $delay . ' second(s).');
            sleep($delay);
        }

        // Let send() report a failed second attempt through its usual exception handler.
        return $this->client->responses()->create($chatRequest);
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
