<?php
namespace rizwanjiwan\commonai\openai;

use Monolog\Logger;

class AbstractResponse
{
    protected Logger $log;
    /**
     * @var string Unique identifier for the response.
     */
    public string $id;
    /**
     * @var string Type of object returned, typically "chat.completion".
     */
    public string $object;
    /**
     * @var int Timestamp of when the response was created.
     */
    public int $createdTime;

    protected function __construct(string $id, string $object, int $createdTime)
    {
        $this->id = $id;
        $this->object = $object;
        $this->createdTime = $createdTime;
        $this->log = new Logger((new \ReflectionClass($this))->getShortName());
    }
}