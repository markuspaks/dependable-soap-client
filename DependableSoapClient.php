<?php

namespace DependableSoapClient;

use Closure;
use SoapClient;
use SoapFault;
use SoapHeader;
use SoapVar;
use stdClass;

class DependableSoapClient extends SoapClient
{
    /**
     * Debug level
     *
     * @var int
     */
    public int $debugLevel = 0;

    /**
     * SOAP endPoint uri
     *
     * @var ?string
     */
    public ?string $endPoint = null;

    /**
     * Should SoapFault be thrown or returned
     *
     * @var bool
     */
    public bool $exceptions = true;

    /**
     * Sets ssl options verify_peer to false and allow_self_signed to true
     *
     * @var bool
     */
    public bool $insecure = true;

    public ?string $localCertificate = null;

    /**
     * Override default SSL version
     *
     * @var ?int
     */
    public ?int $sslVersion = null;

    /**
     * Http authentication options (username, password)
     * @var ?array
     */
    public ?array $httpAuthenticate = null;

    /**
     * SOAP header authentication (username, password)
     * @var ?array
     */
    public ?array $soapAuthenticate = null;

    /**
     * Client status
     *
     * @var int
     */
    public int $status = self::STATUS_OK;

    /**
     * Requests timeout in seconds
     *
     * @var ?int
     */
    public ?int $timeout = null;

    /**
     * Wsdl Cache
     *
     * @var int
     */
    public int $wsdlCache = WSDL_CACHE_NONE;

    protected ?SoapRequest $currentRequest = null;

    protected ?SoapResponse $currentResponse = null;

    /**
     * Total soap calls made
     *
     * @var int
     */
    public static int $totalCalls = 0;

    /**
     * Total time soap calls took
     *
     * @var float
     */
    public static float $totalTime = 0;

    /**
     * @var ?Closure
     */
    protected static ?Closure $logCallback = null;

    protected const DEBUG_BASIC = 1;
    protected const DEBUG_WSDL = 2;
    protected const DEBUG_REQUEST = 4;
    protected const DEBUG_RESPONSE = 8;
    protected const DEBUG_RESPONSE_OBJECT = 16;
    protected const DEBUG_TIMINGS = 32;

    protected const DEBUG_ALL = self::DEBUG_BASIC | self::DEBUG_WSDL | self::DEBUG_REQUEST | self::DEBUG_RESPONSE | self::DEBUG_RESPONSE_OBJECT | self::DEBUG_TIMINGS;

    public const STATUS_OK = 0;
    public const STATUS_ERROR = 1;

    public function __construct($wsdl, array $options = null)
    {
        $options = array_replace_recursive(
            [
                'preload_wsdl' => false,
                'cache_wsdl' => $this->wsdlCache,
                'location' => $this->endPoint,
                'exceptions' => $this->exceptions
            ],
            $options
        );

        if (isset($options['debug_level'])) {
            $this->debugLevel = $options['debug_level'];
        }

        if ($this->debugLevel > 0) {
            $options['trace'] = true;
        }

        // Debug wsdl info
        if ($this->debugLevel & self::DEBUG_WSDL) {
            $this->log('WSDL path: ' . $wsdl);
        }

        if ($options['preload_wsdl'] === true) {
            $wsdl = $this->preloadWsdl($wsdl, $options);

            if ($wsdl === null) {
                return;
            }
        }

        // Bad PHP, bad. This is NOT a feature!
        $options['features'] = SOAP_SINGLE_ELEMENT_ARRAYS;

        $this->localCertificate = $options['local_cert'];

        // Construct parent class
        parent::__construct($wsdl, $options);

        // Debug wsdl info
        if ($this->debugLevel & self::DEBUG_WSDL) {
            $this->log("SOAP Types: " . print_r($this->__getTypes(), true));
            $this->log("SOAP Functions: " . print_r($this->__getFunctions(), true));
        }
    }

    /**
     * Fetch the wsdl file and save it under runtime for later usage
     *
     * @param $wsdl
     * @param $options
     * @param  string|null  $targetWsdl
     * @return null|string
     */
    public function preloadWsdl($wsdl, &$options, ?string $targetWsdl = null): ?string
    {
        // WSDL cache path
        $cachedPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . ($targetWsdl !== null ? $targetWsdl : md5(
                    $wsdl
                ) . '.wsdl');

        // Unlink WSDL cache
        if ($options['cache_wsdl'] == WSDL_CACHE_NONE) {
            @unlink($cachedPath);
        }

        if (file_exists($wsdl)) {
            $cachedPath = $wsdl;
        }

        // Cache does not exist
        if (!file_exists($cachedPath)) {
            $result = $this->request($wsdl);

            if ($result === null) {
                return null;
            }

            //Fetch related wsdl-s with relative path (without /)
            preg_match_all('/schemaLocation="([^"\/]*)"/', $result, $matches);

            if (count($matches) > 1) {
                foreach ($matches[1] as $subWsdl) {
                    //Replace everything after last / with the imported schema file name
                    $subWsdlSrc = preg_replace('/\/([^\/]*)$/', '/' . $subWsdl, $wsdl);
                    $this->preloadWsdl($subWsdlSrc, $options, $subWsdl);
                }
            }

            //Write to cache
            $ok = @file_put_contents($cachedPath, $result);

            if ($ok) {
                @chmod($cachedPath, 0777);
                $wsdl = $cachedPath;
            } else {
                // Log error
                $this->log(
                    'Unable to write cached file "' . $cachedPath . '" for wsdl "' . $wsdl . '"',
                    'error'
                );

                $this->status = self::STATUS_ERROR;
                return null;
            }
            // Cache wsdl exists
        } else {
            $wsdl = $cachedPath;
        }

        // Never cache to file by PHP itself to disk
        if ($options['cache_wsdl'] & WSDL_CACHE_DISK) {
            $options['cache_wsdl'] -= WSDL_CACHE_DISK;
        }

        return $wsdl;
    }

    /**
     * Call soap function as callable
     *
     * @param  string  $name
     * @param  mixed  $args
     * @return mixed
     */
    public function __call($name, $args)
    {
        $args = [$args]; //This was done in soapCall, but caused issues with wsdl that uses parts
        array_unshift($args, $name);
        return call_user_func_array([$this, '__soapCall'], $args);
    }

    /**
     * Do request
     *
     * @param  string  $request
     * @param  string  $location
     * @param  string  $action
     * @param  int  $version
     * @param  bool  $oneWay
     * @return string|null
     */
    public function __doRequest(
        $request,
        $location,
        $action,
        $version,
        $oneWay = false
    ): ?string {
        $response = $this->request($location, $request, true);

        if (!$oneWay) {
            return $response;
        }

        return null;
    }

    public function addAttachment($file): string
    {
        return $this->getSoapRequest()->addAttachment($file);
    }

    public function getAttachments(): array
    {
        return $this->currentResponse->getAttachments();
    }

    public function setHttpAuthentication($username, $password): void
    {
        $this->getSoapRequest()->setHttpAuthentication($username, $password);
    }

    /**
     * Perform cUrl request to the given url
     *
     * @param $url
     * @param  string|null  $request
     * @param  bool  $isPostRequest
     * @return mixed|null
     */
    public function request($url, ?string $request = null, bool $isPostRequest = false): ?string
    {
        $soapRequest = $this->getSoapRequest();

        $soapRequest->setXml($request);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_URL => $url,
        ];

        if ($isPostRequest) {
            $options[CURLOPT_POST] = 1;
        }

        if ($this->localCertificate) {
            $options[CURLOPT_SSLCERT] = $this->localCertificate;
        }

        $options[CURLOPT_HTTPHEADER] = $soapRequest->getHeaders();
        $options[CURLOPT_POSTFIELDS] = $soapRequest->getContents();

        $this->log('Request headers: ' . join("\n", $soapRequest->getHeaders()));
        $this->log('Request body: ' . $soapRequest->getContents());

        if ($this->sslVersion !== null) {
            $options[CURLOPT_SSLVERSION] = $this->sslVersion;
        }

        if ($this->insecure === true) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = false;
        }

        // Use timeout if set
        if ($this->timeout !== null) {
            $options[CURLOPT_TIMEOUT] = $this->timeout;
        }

        $headers = [];

        $options[CURLOPT_HEADERFUNCTION] = function ($curl, $header) use (&$headers) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) < 2) // ignore invalid headers
            {
                return $len;
            }

            $headers[] = $header;

            return $len;
        };


        $resource = curl_init();

        curl_setopt_array($resource, $options);

        $result = curl_exec($resource);

        $this->currentResponse = $response = new SoapResponse($headers, $result);

        // Check for errors and display the error message
        if ($error = curl_errno($resource)) {
            var_dump(curl_error($resource));
            $this->status = self::STATUS_ERROR;
            curl_close($resource);
            return null;
        }

        curl_close($resource);

        $this->log('Result: ' . $result);

        $this->currentRequest = null;

        return $response->getXml();
    }

    /**
     * Inner function for soap call
     *
     * @param  array  $inputHeaders
     * @param  array  $outputHeaders
     * @throws SoapFault
     */
    public function __soapCall(
        $name,
        $args,
        $options = null,
        $inputHeaders = null,
        &$outputHeaders = null
    ) {
        $debug = $this->debugLevel;

        if (is_array($options) && isset($options['debug'])) {
            $debug = $options['debug'];

            // Remove debug option because parent call dos not understand it
            unset($options['debug']);
        }

        if (is_array($this->soapAuthenticate)) {
            $inputHeaders = [];
            $inputHeaders[] = $this->generateBasicAuthenticationHeader(
                $this->soapAuthenticate['username'],
                $this->soapAuthenticate['password']
            );
        }

        // start time
        $callStart = microtime(true);

        // Actual call
        $answer = parent::__soapCall($name, $args, $options, $inputHeaders, $outputHeaders);

        // End time
        $callEnd = microtime(true);

        $callTime = $callEnd - $callStart;

        $this->log(
            'Soap request to ' . $name . ' took ' . round($callTime, 2)
            . ' sec (' . parent::__getLastRequest() . ')',
            'info'
        );

        $this->log('Soap request ' . $name . ' response: ' . parent::__getLastResponse(), 'info');

        if ($answer === null) {
            $answer = new SoapFault('Data fetching error, check logs', 'Data fetching error, check logs');
        }

        if ($debug > 0) {
            $this->debug($debug, $answer);
        }

        self::$totalCalls++;
        self::$totalTime += $callTime;

        return $answer;
    }

    public static function setLogCallback($callback): void
    {
        static::$logCallback = $callback;
    }

    protected function getSoapRequest(): ?SoapRequest
    {
        if ($this->currentRequest === null) {
            $this->currentRequest = new SoapRequest();
        }

        return $this->currentRequest;
    }

    /**
     * Logs last soap call debug info
     *
     * @param  int  $debug
     * @param  mixed  $answer
     */
    protected function debug(int $debug, $answer): void
    {
        if ($debug & self::DEBUG_REQUEST) {
            $this->log("Request XML:" . $this->formatXmlString($this->__getLastRequest()));
        }

        if ($debug & self::DEBUG_RESPONSE) {
            $this->log("Response XML:" . $this->formatXmlString($this->__getLastResponse()));
        }

        if ($debug & self::DEBUG_RESPONSE_OBJECT) {
            $this->log("Response object: " . print_r($answer, true));
        }
    }

    /**
     * Indent xml
     *
     * @link http://recursive-design.com/blog/2007/04/05/format-xml-with-php/
     * @param  string  $xml
     * @return string
     */
    protected function formatXmlString(string $xml): string
    {
        // add marker linefeeds to aid the pretty-tokeniser (adds a linefeed between all tag-end boundaries)
        $xml = preg_replace('/(>)(<)(\/*)/', "$1\n$2$3", $xml);

        // now indent the tags
        $token = strtok($xml, "\n");
        $result = ''; // holds formatted version as it is built
        $pad = 0; // initial indent
        $matches = []; // returns from preg_matches()

        // scan each line and adjust indent based on opening/closing tags
        while ($token !== false) {
            // test for the various tag states

            // 1. open and closing tags on same line - no change
            if (preg_match('/.+<\/\w[^>]*>$/', $token, $matches)) :
                $indent = 0;
            // 2. closing tag - outdent now
            elseif (preg_match('/^<\/\w/', $token, $matches)) :
                $pad--;
                $indent = 0;
            // 3. opening tag - don't pad this one, only subsequent tags
            elseif (preg_match('/^<\w[^>]*[^\/]>.*$/', $token, $matches)) :
                $indent = 1;
            // 4. no indentation needed
            else :
                $indent = 0;
            endif;

            // pad the line with the required number of leading spaces
            $line = str_pad($token, strlen($token) + $pad, ' ', STR_PAD_LEFT);
            $result .= $line . "\n"; // add to the cumulative result, with linefeed
            $token = strtok("\n"); // get the next token
            $pad += $indent; // update the pad size for subsequent lines
        }

        return $result;
    }

    /**
     * Generate simple authentication usernameToken
     *
     * @param  string  $username
     * @param  string  $password
     * @return SOAPHeader
     */
    protected function generateBasicAuthenticationHeader(string $username, string $password): SoapHeader
    {
        /** @noinspection HttpUrlsUsage */
        $ns = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

        $token = new stdClass();
        $token->Username = new SOAPVar($username, XSD_STRING, null, null, null, $ns);
        $token->Password = new SOAPVar($password, XSD_STRING, null, null, null, $ns);

        $wsec = new stdClass();
        $wsec->UsernameToken = new SoapVar($token, SOAP_ENC_OBJECT, null, null, null, $ns);

        return new SOAPHeader($ns, 'Security', $wsec, true);
    }

    /**
     * Log information
     *
     * @param  string  $message
     * @param  string  $level  one of error, info, trace
     */
    protected function log(string $message, string $level = 'trace'): void
    {
        if (is_callable(static::$logCallback)) {
            $callback = static::$logCallback;
            $callback($message, $level);
        }
    }
}
