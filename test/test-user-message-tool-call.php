<?php

declare(strict_types=1);

/**
 * Regression checks for Responses API tool-call payloads and retries.
 *
 * The SDK exposes response-only status fields through toArray(). This test
 * verifies that UserMessage removes those fields before sending the model's
 * function call back as request input. It also checks that a retry reuses
 * the full payload and does not execute a tool a second time.
 */

use GuzzleHttp\Psr7\Response;
use OpenAI\Contracts\ResponseContract;
use OpenAI\Contracts\ResponseStreamContract;
use OpenAI\Exceptions\ServerException;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Requests\TestRequest;
use OpenAI\Testing\Responses\Fixtures\Responses\CreateResponseFixture;
use rizwanjiwan\commonai\openai\ApiException;
use rizwanjiwan\commonai\openai\Tool;
use rizwanjiwan\commonai\openai\ToolParameter;
use rizwanjiwan\commonai\openai\UserMessage;

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
    public int $executionCount = 0;

    /**
     * Count invocations so a repeated tool action is visible to the test.
     *
     * @param array $parameters Model-supplied arguments for the weather lookup.
     * @return array The temperature sent back to the model.
     */
    public function execute(array $parameters): array
    {
        $this->executionCount++;
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

// Fail both API calls once. The second retry must include the tool result from the first execution.
$temporaryFailure = new ServerException(new Response(503, ['Retry-After' => '0', 'x-request-id' => 'test-request']));
$retryClient = new RecordingClientFake([
    new ServerException(new Response(503)),
    CreateResponse::from($toolResponseAttributes, CreateResponse::fakeResponseMetaInformation()),
    $temporaryFailure,
    CreateResponse::from($finalResponseAttributes, CreateResponse::fakeResponseMetaInformation()),
]);
$tool = new RegressionTool();
$reply = (new UserMessage($retryClient, 'test-model'))
    ->setPrompt('What is the temperature in Toronto?')
    ->provideTool($tool)
    ->send();
requireToolCallCheck($reply->message === '20 degrees', 'The retry did not return the final reply.');
requireToolCallCheck($tool->executionCount === 1, 'The tool must execute exactly once.');
requireToolCallCheck(count($retryClient->requests) === 4, 'Expected one retry per failed API call.');
requireToolCallCheck($retryClient->requests[0] === $retryClient->requests[1], 'The initial payload changed on retry.');
requireToolCallCheck($retryClient->requests[2] === $retryClient->requests[3], 'The follow-up payload changed on retry.');
requireToolCallCheck($retryClient->requests[3]['input'][2]['type'] === 'function_call_output', 'Missing saved tool output.');

/**
 * Verify the request count and original SDK exception exposed by a failed send.
 *
 * @param list<ServerException> $failures SDK exceptions in the order the API returns them.
 * @return void
 * @throws RuntimeException If the send succeeds, makes an extra request, or wraps the wrong exception.
 */
function checkFailedSend(array $failures): void
{
    $client = new RecordingClientFake($failures);
    try {
        (new UserMessage($client, 'test-model'))->setPrompt('Hello')->send();
    } catch (ApiException $e) {
        requireToolCallCheck($e->getPrevious() === $failures[array_key_last($failures)], 'Expected the final SDK exception.');
        requireToolCallCheck(count($client->requests) === count($failures), 'Unexpected number of response requests.');
        return;
    }
    throw new RuntimeException('Expected the send to fail.');
}

checkFailedSend([$temporaryFailure, new ServerException(new Response(503))]);
checkFailedSend([new ServerException(new Response(503, ['Retry-After' => '60']))]);
checkFailedSend([new ServerException(new Response(503, ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 60) . ' GMT']))]);
checkFailedSend([new ServerException(new Response(500))]);
echo "UserMessage retry checks passed.\n";
