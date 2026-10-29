<?php

namespace Joba\Esign;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Joba\Esign\Exceptions\EsignException;
use Joba\Esign\Exceptions\InvalidResponseException;
use Joba\Esign\Exceptions\TransportException;
use Joba\Esign\Resources\File;
use Joba\Esign\Resources\Identity;
use Joba\Esign\Resources\Order;
use Joba\Esign\Resources\Organization;
use Joba\Esign\Resources\Seal;
use Joba\Esign\Resources\SignFlow;
use Joba\Esign\Resources\Template;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * @property-read File $file
 * @property-read Template $template
 * @property-read SignFlow $signFlow
 * @property-read SignFlow $signflow
 * @property-read Identity $identity
 * @property-read Seal $seal
 * @property-read Organization $organization
 * @property-read Order $order
 */
class Client
{
    public const SANDBOX_URL = 'https://smlopenapi.esign.cn';
    public const PRODUCTION_URL = 'https://openapi.esign.cn';

    private ClientInterface $httpClient;
    private Signer $signer;
    private CallbackVerifier $callbackVerifier;
    private string $baseUrl;
    private float $timeout;
    private float $connectTimeout;
    private float $uploadTimeout;
    private array $resources = [];

    public function __construct(array $config, ?ClientInterface $httpClient = null)
    {
        $appId = (string) ($config['app_id'] ?? '');
        $secret = (string) ($config['app_secret'] ?? '');
        $this->signer = new Signer($appId, $secret);
        $this->callbackVerifier = new CallbackVerifier($secret, (int) ($config['callback_tolerance'] ?? 0));
        $sandbox = $config['sandbox'] ?? true;
        if (!is_bool($sandbox)) {
            throw new InvalidArgumentException('sandbox must be a boolean.');
        }
        $override = $config['base_url'] ?? '';
        if (!is_string($override)) {
            throw new InvalidArgumentException('base_url must be a string or null.');
        }
        $this->baseUrl = rtrim(trim($override) === ''
            ? ($sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL)
            : $override, '/');
        $url = $this->httpsUrl($this->baseUrl);
        if (isset($url['query']) || (isset($url['path']) && $url['path'] !== '')) {
            throw new InvalidArgumentException('base_url must be an HTTPS origin without a path or query.');
        }
        $this->timeout = $this->positiveTimeout($config['timeout'] ?? 30, 'timeout');
        $this->connectTimeout = $this->positiveTimeout($config['connect_timeout'] ?? 10, 'connect_timeout');
        $this->uploadTimeout = $this->positiveTimeout($config['upload_timeout'] ?? 120, 'upload_timeout');
        $this->httpClient = $httpClient ?? new HttpClient();
    }

    /**
     * Return the complete API envelope. HTTP and business failures throw exceptions.
     * Pass query parameters separately so their wire and signature ordering cannot drift.
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            throw new InvalidArgumentException('Unsupported HTTP method.');
        }
        $this->validatePath($path);
        // Match PSR-7 URI normalization before computing the signature.
        $path = (new Uri($path))->getPath();
        if ($method === 'GET' && $body !== null) {
            throw new InvalidArgumentException('GET requests cannot contain a body.');
        }

        // Serialize exactly once. The checksum and transmitted content use these same bytes.
        $rawBody = $body === null ? '' : json_encode((object) $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $canonicalQuery = $this->signer->canonicalQuery($query);
        $encodedQuery = $this->signer->encodedQuery($query);
        $resource = $path . ($canonicalQuery === '' ? '' : '?' . $canonicalQuery);
        $url = $this->baseUrl . $path . ($encodedQuery === '' ? '' : '?' . $encodedQuery);
        $request = new Request($method, $url, $this->signer->headers($method, $resource, $rawBody), $rawBody);
        try {
            $response = $this->httpClient->send($request, $this->options($this->timeout));
        } catch (GuzzleException $exception) {
            // Do not retry mutations: a timeout does not prove that E签宝 rejected the operation.
            throw new TransportException('E签宝 API transport failed: ' . $method . ' ' . $path,
                null, 0, null, null, $exception);
        }

        return $this->decodeResponse($response);
    }

    /** PUT bytes to a presigned upload URL without any E签宝 authentication headers. */
    public function upload(string $url, string $localPath, string $contentType): void
    {
        $this->httpsUrl($url);
        if (strpos($localPath, '://') !== false || strpos($localPath, "\0") !== false
            || !is_file($localPath) || !is_readable($localPath) || filesize($localPath) <= 0) {
            throw new InvalidArgumentException('Upload requires a nonempty readable local file.');
        }
        if (trim($contentType) === '' || preg_match('/[\r\n]/', $contentType)) {
            throw new InvalidArgumentException('Invalid upload content type.');
        }
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('Unable to open upload file.');
        }
        try {
            $hash = hash_init('md5');
            hash_update_stream($hash, $stream);
            $checksum = base64_encode(hash_final($hash, true));
            rewind($stream);
            $request = new Request('PUT', $url, [
                'Content-Type' => $contentType,
                'Content-MD5' => $checksum,
            ], $stream);
            $response = $this->httpClient->send($request, $this->options($this->uploadTimeout));
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new EsignException('E签宝 file upload failed.', null, $response->getStatusCode());
            }
        } catch (GuzzleException $exception) {
            throw new TransportException('E签宝 file upload transport failed.', null, 0, null, null, $exception);
        } finally {
            fclose($stream);
        }
    }

    public function file(): File { return $this->resource('file', File::class); }
    public function template(): Template { return $this->resource('template', Template::class); }
    public function signFlow(): SignFlow { return $this->resource('signFlow', SignFlow::class); }
    public function identity(): Identity { return $this->resource('identity', Identity::class); }
    public function seal(): Seal { return $this->resource('seal', Seal::class); }
    public function organization(): Organization { return $this->resource('organization', Organization::class); }
    public function order(): Order { return $this->resource('order', Order::class); }
    public function callback(): CallbackVerifier { return $this->callbackVerifier; }

    public function __get(string $name)
    {
        $name = $name === 'signflow' ? 'signFlow' : $name;
        if (!in_array($name, ['file', 'template', 'signFlow', 'identity', 'seal', 'organization', 'order'], true)) {
            throw new InvalidArgumentException('Unknown E签宝 resource: ' . $name);
        }

        return $this->{$name}();
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['file', 'template', 'signFlow', 'signflow', 'identity', 'seal', 'organization', 'order'], true);
    }

    private function resource(string $name, string $class)
    {
        if (!isset($this->resources[$name])) {
            $this->resources[$name] = new $class($this);
        }

        return $this->resources[$name];
    }

    private function options(float $timeout): array
    {
        return [
            // Guzzle's documented null disables default headers. Explicit PSR request
            // headers survive; API credentials never become defaults on upload requests.
            'headers' => null,
            'query' => null,
            'json' => null,
            'form_params' => null,
            'multipart' => null,
            'body' => null,
            'sink' => null,
            'timeout' => $timeout,
            'connect_timeout' => $this->connectTimeout,
            'http_errors' => false,
            'allow_redirects' => false,
            'verify' => true,
            'auth' => null,
            'cookies' => false,
        ];
    }

    private function decodeResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $data = null;
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && substr(ltrim($raw), 0, 1) === '{') {
                $data = $decoded;
            }
        } catch (JsonException $exception) {
            // HTTP failures retain their status even when a gateway returned HTML.
        }
        $requestId = $response->getHeaderLine('X-Tsign-Open-Ca-Request-Id')
            ?: $response->getHeaderLine('X-Tsign-Open-Request-Id')
            ?: $response->getHeaderLine('X-Request-Id');
        if ($requestId === '' && isset($data['requestId']) && is_scalar($data['requestId'])) {
            $requestId = (string) $data['requestId'];
        }
        $requestId = $requestId === '' ? null : $requestId;
        $code = $data['code'] ?? null;
        if ($status < 200 || $status >= 300) {
            throw new EsignException('E签宝 API HTTP error: ' . $status, $code, $status, $requestId, $data);
        }
        if ($data === null || !array_key_exists('code', $data)
            || (!is_int($code) && !is_string($code))) {
            throw new InvalidResponseException('E签宝 API returned an invalid JSON envelope.', null, $status, $requestId, $data);
        }
        if ($code !== 0 && $code !== '0') {
            $message = isset($data['message']) && is_string($data['message']) ? $data['message'] : 'Unknown error';
            throw new EsignException('E签宝 API error [' . $code . ']: ' . $message, $code, $status, $requestId, $data);
        }

        return $data;
    }

    private function positiveTimeout($value, string $name): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || $value <= 0) {
            throw new InvalidArgumentException($name . ' must be a finite positive number.');
        }

        return (float) $value;
    }

    private function httpsUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new InvalidArgumentException('An absolute HTTPS URL without credentials or fragment is required.');
        }

        return $parts;
    }

    private function validatePath(string $path): void
    {
        if (strpos($path, '/v3/') !== 0 || preg_match('/[?#\\\\\x00-\x20\x7f]/', $path)
            || strpos($path, '//') !== false || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', rawurldecode($path))) {
            throw new InvalidArgumentException('API path must start with /v3/ and contain no query, fragment or traversal.');
        }
    }
}
