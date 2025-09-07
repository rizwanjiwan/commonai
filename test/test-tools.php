<?php

use rizwanjiwan\common\classes\Config;
use rizwanjiwan\common\classes\LogManager;
use rizwanjiwan\commonai\openai\Client;

error_reporting(E_ALL & ~E_DEPRECATED);

date_default_timezone_set('America/Toronto');
require_once realpath(dirname(__FILE__)).'/../vendor/autoload.php';
require_once realpath(dirname(__FILE__)).'/WeatherTool.php';

$log=LogManager::createLogger('TestTools');

$log->info('Starting');
try{
    $client=new Client(Config::get('OPEN_AI_KEY'), Config::get('OPEN_AI_MODEL'));
    $message=$client->createUserMessage();
    $log->info('Created message');
    $response=$message
        ->setPrompt("What's the temperature in the city of Toronto?")
        ->provideTool(new Weathertool())
        ->send();
    $log->info('Sent message');
    echo $response->message."\n";
}
catch(Exception $e){
    $log->error($e->getMessage()." ".$e->getTraceAsString());
    echo "Error: ".$e->getMessage();
}

