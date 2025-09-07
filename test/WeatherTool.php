<?php

use Monolog\Logger;
use rizwanjiwan\common\classes\LogManager;
use rizwanjiwan\commonai\openai\Tool;
use rizwanjiwan\commonai\openai\ToolParameter;

class WeatherTool implements Tool
{

    private Logger $log;

    public function __construct()
    {
        $this->log=LogManager::createLogger('Weathertool');
    }
    public function execute(array $parameters): array
    {
        return array('temperature' => '20');
    }

    public function getName(): string
    {
        return 'getTemperature';
    }

    public function getDescription(): string
    {
        return 'Get the temperature for a specific city';
    }

    public function getParameters(): array
    {
        return [
            (new ToolParameter('city', ['string'], 'The city to get the temperature for'))
                ->setIsRequired(true),
        ];
    }
}