<?php

use rizwanjiwan\common\classes\Config;
use rizwanjiwan\common\classes\LogManager;
use rizwanjiwan\commonai\openai\Client;

error_reporting(E_ALL & ~E_DEPRECATED);

date_default_timezone_set('America/Toronto');
require_once realpath(dirname(__FILE__)).'/../vendor/autoload.php';
require_once realpath(dirname(__FILE__)).'/WeatherTool.php';

$log=LogManager::createLogger('FileTest');

$log->info('Starting');
try{
    $client=new Client(Config::get('OPEN_AI_KEY'), Config::get('OPEN_AI_MODEL'));
    $file=$client->uploadFile(realpath(dirname(__FILE__)).'/cloud-story.txt');
    $message=$client->createUserMessage();
    $log->info('Created message');
    $response=$message
        ->includeFile($file->id)
        ->setPrompt("From the included file, who did the little cloud help?")
        ->send();
    $log->info('Sent message');
    echo $response->message."\n";
    $client->deleteFile($file->id);
}
catch(Exception $e){
    $log->error($e->getMessage()." ".$e->getTraceAsString());
    echo "Error: ".$e->getMessage();
}

