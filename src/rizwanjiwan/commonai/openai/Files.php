<?php

namespace rizwanjiwan\commonai\openai;


class Files
{
    /**
     * @var FileResponse[] Array of FileResponse objects.
     */
    public array $responses=array();

    public function addFile(FileResponse $response):void
    {
        array_push($this->responses,$response);
    }
}