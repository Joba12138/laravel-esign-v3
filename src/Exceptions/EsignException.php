<?php

namespace Joba\Esign\Exceptions;

use RuntimeException;
use Throwable;

class EsignException extends RuntimeException
{
    /** @var int|string|null */
    private $businessCode;
    private int $httpStatus;
    private ?string $requestId;
    private ?array $responseData;

    public function __construct(
        string $message,
        $businessCode = null,
        int $httpStatus = 0,
        ?string $requestId = null,
        ?array $responseData = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->businessCode = $businessCode;
        $this->httpStatus = $httpStatus;
        $this->requestId = $requestId;
        $this->responseData = $responseData;
    }

    /** @return int|string|null */
    public function getBusinessCode()
    {
        return $this->businessCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** Original envelope, for explicit diagnostics; it may contain private data. */
    public function getResponseData(): ?array
    {
        return $this->responseData;
    }
}
