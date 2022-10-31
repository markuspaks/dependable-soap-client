<?php

namespace DependableSoapClient;

use ZBateson\MailMimeParser\Message;

class SoapResponse
{

    protected $attachments = [];

    protected $headers;

    protected $response;

    protected $xml;

    public function __construct(array $headers, string $response)
    {
        $this->headers = $headers;
        $this->response = $response;
        $this->handle();
    }

    public function getXml()
    {
        return $this->xml;
    }

    public function getAttachments()
    {
        return $this->attachments;
    }

    protected function handle()
    {
        if (substr($this->response, 0, 2) === '--') {
            $mailHeader = join("", $this->headers)."\r\n";

            $mailMessage = $mailHeader.$this->response;
            $message = Message::from($mailMessage, true);

            $part = $message->getPart(1);
            $this->xml = $part->getContent();

            $this->attachments = $message->getAllAttachmentParts();

            // Take first part (xml) off
            array_shift($this->attachments);
        } else {
            $this->xml = $this->response;
        }
    }
}