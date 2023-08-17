<?php

namespace DependableSoapClient;

use ZBateson\MailMimeParser\Message;

class SoapResponse
{

    protected array $attachments = [];

    protected array $headers;

    protected string $response;

    protected string $xml;

    public function __construct(array $headers, string $response)
    {
        $this->headers = $headers;
        $this->response = $response;
        $this->handle();
    }

    public function getXml(): string
    {
        return $this->xml;
    }

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    protected function handle(): void
    {
        if (str_starts_with($this->response, '--')) {
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