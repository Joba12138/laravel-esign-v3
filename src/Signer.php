<?php

namespace Joba\Esign;

use InvalidArgumentException;

/** Signature authentication for E签宝 SaaS API V3. */
class Signer
{
    public const ACCEPT = '*/*';
    public const CONTENT_TYPE = 'application/json; charset=UTF-8';

    private string $appId;
    private string $appSecret;

    public function __construct(string $appId, string $appSecret)
    {
        if (trim($appId) === '' || trim($appSecret) === '') {
            throw new InvalidArgumentException('E签宝 app_id and app_secret are required.');
        }
        $this->appId = $appId;
        $this->appSecret = $appSecret;
    }

    /** The gateway signs decoded values; the wire query is encoded separately. */
    public function canonicalQuery(array $query): string
    {
        $encoded = $this->encodedQuery($query);
        if ($encoded === '') {
            return '';
        }
        $parameters = [];
        foreach (explode('&', $encoded) as $parameter) {
            [$key, $value] = explode('=', $parameter, 2);
            // Official V3 rule: an empty value contributes its key, without '='.
            $parameters[] = rawurldecode($key) . ($value === '' ? '' : '=' . rawurldecode($value));
        }

        return implode('&', $parameters);
    }

    public function encodedQuery(array $query): string
    {
        $normalized = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (!is_scalar($value) || (is_float($value) && !is_finite($value))) {
                throw new InvalidArgumentException('Query values must be finite scalars or null.');
            }
            $normalized[$key] = is_bool($value) ? (int) $value : $value;
        }
        ksort($normalized, SORT_STRING);

        return http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
    }

    public function signature(string $method, string $resource, string $body = ''): string
    {
        $md5 = $this->contentMd5($method, $body);
        $message = strtoupper($method) . "\n" . self::ACCEPT . "\n" . $md5 . "\n"
            . self::CONTENT_TYPE . "\n\n" . $resource;

        return base64_encode(hash_hmac('sha256', $message, $this->appSecret, true));
    }

    public function headers(string $method, string $resource, string $body = '', ?string $timestamp = null): array
    {
        $headers = [
            'Accept' => self::ACCEPT,
            'Content-Type' => self::CONTENT_TYPE,
            'X-Tsign-Open-App-Id' => $this->appId,
            'X-Tsign-Dns-App-Id' => $this->appId,
            'X-Tsign-Open-Auth-Mode' => 'Signature',
            'X-Tsign-Open-Ca-Timestamp' => $timestamp ?? (string) (int) floor(microtime(true) * 1000),
            'X-Tsign-Open-Ca-Signature' => $this->signature($method, $resource, $body),
        ];
        $md5 = $this->contentMd5($method, $body);
        if ($md5 !== '') {
            $headers['Content-MD5'] = $md5;
        }

        return $headers;
    }

    private function contentMd5(string $method, string $body): string
    {
        // Official PHP demo computes MD5 even for empty POST/PUT bodies.
        // Bodyless GET/DELETE omit it; an explicit DELETE body still signs its bytes.
        if ($body === '' && in_array(strtoupper($method), ['GET', 'DELETE'], true)) {
            return '';
        }

        return base64_encode(md5($body, true));
    }
}
