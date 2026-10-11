<?php

declare(strict_types=1);

/**
 * Verifies that project entry points can be loaded and constructed on PHP 8.5.
 *
 * This is intentionally a small executable regression test because the
 * project does not currently use a test framework. It performs no API calls.
 */

use rizwanjiwan\commonai\openai\ApiException;
use rizwanjiwan\commonai\openai\Client;
use rizwanjiwan\commonai\openai\ToolParameter;

require_once dirname(__DIR__).'/vendor/autoload.php';

/**
 * Assert a PHP 8.5 compatibility condition.
 *
 * @param bool $condition The condition that must be true.
 * @param string $message The failure message to report.
 * @throws RuntimeException When the condition is false.
 */
function requireCompatibilityCheck(bool $condition, string $message): void
{
    if($condition===false)
        throw new RuntimeException($message);
}

set_error_handler(
    static function(int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
);

try
{
    requireCompatibilityCheck(class_exists(Client::class), 'The OpenAI client class could not be autoloaded.');
    requireCompatibilityCheck(class_exists(ApiException::class), 'The API exception class could not be autoloaded.');

    $client=new Client('php85-compatibility-key', 'php85-compatibility-model');
    requireCompatibilityCheck($client instanceof Client, 'The OpenAI client could not be constructed.');

    $parameter=(new ToolParameter('city', array('string'), 'The city to check'))
        ->setEnum(array('Toronto'))
        ->setIsRequired(true);
    requireCompatibilityCheck($parameter->isRequired===true, 'Tool parameter required state was not retained.');
    requireCompatibilityCheck($parameter->enum===array('Toronto'), 'Tool parameter enum was not retained.');

    echo "PHP 8.5 compatibility checks passed.\n";
}
finally
{
    restore_error_handler();
}
