<?php

namespace Joba\Esign;

use InvalidArgumentException;
use Joba\Esign\Exceptions\CallbackVerificationException;
use Joba\Esign\Exceptions\InvalidResponseException;
use JsonException;

class CallbackVerifier
{
    private string $appSecret;
    private int $toleranceSeconds;

    /** Optional freshness safeguard; disabled by default to permit delayed official retries. */
    public function __construct(string $appSecret, int $toleranceSeconds = 0)
    {
        if (trim($appSecret) === '' || $toleranceSeconds < 0) {
            throw new InvalidArgumentException('A secret and a non-negative callback tolerance are required.');
        }
        $this->appSecret = $appSecret;
        $this->toleranceSeconds = $toleranceSeconds;
    }

    public function verify(
        string $rawBody,
        string $signature,
        string $timestamp,
        array $query = [],
        ?int $nowMilliseconds = null
    ): bool {
        if (!preg_match('/^[0-9]{13}$/D', $timestamp) || !preg_match('/^[a-fA-F0-9]{64}$/D', $signature)) {
            return false;
        }
        $nowMilliseconds = $nowMilliseconds ?? (int) floor(microtime(true) * 1000);
        if ($this->toleranceSeconds > 0
            && abs($nowMilliseconds - (int) $timestamp) > $this->toleranceSeconds * 1000) {
            return false;
        }

        ksort($query, SORT_STRING);
        $queryValues = '';
        foreach ($query as $value) {
            if (!is_scalar($value)) {
                return false;
            }
            $queryValues .= (string) $value;
        }

        $expected = hash_hmac('sha256', $timestamp . $queryValues . $rawBody, $this->appSecret);

        return hash_equals($expected, strtolower($signature));
    }

    /** Decode only after authenticating the exact original HTTP body. */
    public function parse(
        string $rawBody,
        string $signature,
        string $timestamp,
        array $query = [],
        ?int $nowMilliseconds = null
    ): array {
        if (!$this->verify($rawBody, $signature, $timestamp, $query, $nowMilliseconds)) {
            throw new CallbackVerificationException('E签宝 callback signature or timestamp is invalid.');
        }
        try {
            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidResponseException('E签宝 callback body is not valid JSON.', null, 0, null, null, $exception);
        }
        if (!is_array($data) || substr(ltrim($rawBody), 0, 1) !== '{') {
            throw new InvalidResponseException('E签宝 callback body must be a JSON object.');
        }

        return $data;
    }
}
