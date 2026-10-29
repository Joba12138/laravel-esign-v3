<?php

namespace Joba\Esign\Resources;

use InvalidArgumentException;
use Joba\Esign\Client;

abstract class Resource
{
    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    protected function segment(string $value): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('Resource ID must not be empty.');
        }

        return rawurlencode($value);
    }
}
