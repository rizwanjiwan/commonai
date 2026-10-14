<?php

declare(strict_types=1);

/**
 * Regression check for Responses API tool-call continuation payloads.
 *
 * The SDK exposes response-only status fields through toArray(). This test
 * verifies that UserMessage removes those fields before sending the model's
 * function call back as request input.
 */

use OpenAI\Contracts\ResponseContract;
use OpenAI\Contracts\ResponseStreamContract;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Requests\TestRequest;
use OpenAI\Testing\Responses\Fixtures\Responses\CreateResponseFixture;
use rizwanjiwan\commonai\openai\Tool;
use rizwanjiwan\commonai\openai\ToolParameter;

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/src/rizwanjiwan/commonai/openai/ApiException.php';
require_once dirname(__DIR__).'/src/rizwanjiwan/commonai/openai/AbstractResponse.php';
require_once dirname(__DIR__).'/src/rizwanjiwan/commonai/openai/MessageResponse.php';
require_once dirname(__DIR__).'/src/rizwanjiwan/commonai/openai/Tool.php';
require_once dirname(__DIR__).'/src/rizwanjiwan/commonai/openai/ToolParameter.php';
require_once dirname(__DIR__).'/src/rizwanjiwan/commonai/openai/UserMessage.php';

/**
 * Records response creation parameters while reusing the SDK's response queue.
 */
final class RecordingClientFake extends ClientFake
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $requests=[];

    /**
     * @param TestRequest $request The request emitted by an SDK test resource.
     * @return ResponseContract|ResponseStreamContract|string
     */
    public function record(TestRequest $request): ResponseContract|ResponseStreamContract|string
    {
        if($request->method()==='create'){
            $arguments=$request->args();
            $this->requests[]=$arguments[0];
        }
        return parent::record($request);
    }
}

/**
 * Minimal tool used to force the continuation path under test.
 */
final class RegressionTool implements Tool
{
    public function execute(array $parameters): array
    {
        return array('temperature'=>'20');
    }

    public function getName(): string
    {
        return 'getTemperature';
    }

    public function getDescription(): string
    {
        return 'Get the temperature for a city';
    }

    public function getParameters(): array
    {
        return array(new ToolParameter('city',array('string'),'The city to check'));
    }
}

/**
 * Assert a regression-test condition.
 *
 * @param bool $condition The condition that must be true.
 * @param string $message The failure message to report.
 * @throws RuntimeException When the condition is false.
 */
function requireToolCallCheck(bool $condition, string $message): void
{
    if($condition===false){
        throw new RuntimeException($message);
    }
}

$toolResponseAttributes=CreateResponseFixture::ATTRIBUTES;
$toolResponseAttributes['output']=array(
    array(
        'type'=>'function_call',
        'id'=>'fc_123',
        'status'=>'completed',
        'name'=>'getTemperature',
        'call_id'=>'call_123',
        'arguments'=>'{"city":"Toronto"}',
    ),
);

$finalResponseAttributes=CreateResponseFixture::ATTRIBUTES;
$finalResponseAttributes['output']=array(
    array(
        'type'=>'message',
        'id'=>'msg_123',
        'status'=>'completed',
        'role'=>'assistant',
        'content'=>array(
            array(
                'type'=>'output_text',
                'text'=>'20 degrees',
                'annotations'=>array(),
            ),
        ),
    ),
);

$client=new RecordingClientFake(array(
    CreateResponse::from($toolResponseAttributes,CreateResponse::fakeResponseMetaInformation()),
    CreateResponse::from($finalResponseAttributes,CreateResponse::fakeResponseMetaInformation()),
));

$message=new \rizwanjiwan\commonai\openai\UserMessage($client,'test-model');
$message
    ->setPrompt('What is the temperature in Toronto?')
    ->provideTool(new RegressionTool())
    ->send();

requireToolCallCheck(count($client->requests)===2,'The tool-call continuation did not make two response requests.');
requireToolCallCheck(isset($client->requests[1]['input'][1])===true,'The continuation request did not include the function call input.');
requireToolCallCheck(array_key_exists('status',$client->requests[1]['input'][1])===false,'The continuation request included the response-only status field.');
requireToolCallCheck($client->requests[1]['input'][1]['type']==='function_call','The continuation request included the wrong input item type.');

echo "UserMessage tool-call continuation check passed.\n";
