<?php

namespace DependableSoapClient;

class SoapRequest
{
    const BOUNDARY = 'MimeBoundaryOkOiKi';

    protected ?string $action = null;

    protected ?array $httpAuthentication = null;

    protected array $attachments = [];

    protected string $xml;

    /**
     * @param  SoapAttachment  $attachment
     * @return string CID of attachment
     */
    public function addAttachment(SoapAttachment $attachment): string
    {
        $this->attachments[] = $attachment;
        return $attachment->getContentID();
    }

    public function setAction($action): void
    {
        $this->action = $action;
    }

    public function setHttpAuthentication($username, $password): void
    {
        $this->httpAuthentication = ['username' => $username, 'password' => $password];
    }

    public function setXml($xml): void
    {
        $this->xml = $xml;
    }

    public function getHeaders(): array
    {
        $headers = [];

        $contents = $this->getContents();

        if (count($this->attachments)) {
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: Multipart/Related; type="text/xml"; start="<main_envelope>"; boundary='.self::BOUNDARY;
        } else {
            $headers[] = 'Content-type: text/xml';
        }

        $headers[] = 'SOAPAction: "'.$this->action.'"';
        $headers[] = 'Content-length: '.mb_strlen($contents);

        if (is_array($this->httpAuthentication)) {
            $headers[] = 'Authorization: Basic '.
                base64_encode($this->httpAuthentication['username'].':'.$this->httpAuthentication['password']);
        }

        return $headers;
    }

    public function getContents(): string
    {
        if (count($this->attachments)) {
            return $this->contentWithAttachments();
        }

        return $this->contentWithoutAttachments();
    }

    private function contentWithAttachments(): string
    {
        $content = [];

        $content[] = '--'.static::BOUNDARY;
        $content[] = 'Content-Type: text/xml';
        $content[] = 'Content-ID: <main_envelope>';
        $content[] = '';
        $content[] = $this->xml;

        /** @var SoapAttachment $attachment */
        foreach ($this->attachments as $attachment) {
            $content[] = '--'.static::BOUNDARY;
            $content[] = 'Content-Type: '.$attachment->getMimeType();
            $content[] = 'Content-ID: '.$attachment->getContentID();
            $content[] = 'Content-Transfer-Encoding: base64';
            $content[] = '';
            $content[] = base64_encode($attachment->getContents());
        }

        $content[] = '--'.static::BOUNDARY.'--';

        return join("\r\n", $content);
    }

    private function contentWithoutAttachments(): string
    {
        return $this->xml;
    }
}
