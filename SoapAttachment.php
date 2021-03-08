<?php

namespace DependableSoapClient;

class SoapAttachment
{

    protected string $filePath;

    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }

    public function getContentID()
    {
        return basename($this->filePath);
    }

    public function getFilePath()
    {
        return $this->filePath;
    }

    public function getMimeType() : string
    {
        return mime_content_type($this->filePath);
    }

    public function getContents() : string
    {
        return file_get_contents($this->filePath);
    }
}
