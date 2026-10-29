<?php

namespace Joba\Esign\Resources;

use InvalidArgumentException;

class SignFlow extends Resource
{
    public function createByFile(array $body): array
    {
        return $this->client->request('POST', '/v3/sign-flow/create-by-file', $body);
    }

    public function signUrl(string $signFlowId, array $body): array
    {
        return $this->client->request('POST', $this->path($signFlowId, '/sign-url'), $body);
    }

    public function batchSignUrl(array $body): array
    {
        return $this->client->request('POST', '/v3/sign-flow/batch-sign-url', $body);
    }

    public function downloadUrls(string $signFlowId, array $query = []): array
    {
        return $this->client->request('GET', $this->path($signFlowId, '/file-download-url'), null, $query);
    }

    public function revoke(string $signFlowId, ?array $body = null): array
    {
        return $this->client->request('POST', $this->path($signFlowId, '/revoke'), $body);
    }

    public function urge(string $signFlowId, array $body): array
    {
        return $this->client->request('POST', $this->path($signFlowId, '/urge'), $body);
    }

    public function finish(string $signFlowId): array
    {
        return $this->client->request('POST', $this->path($signFlowId, '/finish'));
    }

    public function detail(string $signFlowId): array
    {
        return $this->client->request('GET', $this->path($signFlowId, '/detail'));
    }

    public function search(array $body): array
    {
        return $this->client->request('POST', '/v3/sign-flow/sign-flow-list', $body);
    }

    public function addSignFields(string $signFlowId, array $body): array
    {
        return $this->client->request('POST', $this->path($signFlowId, '/signers/sign-fields'), $body);
    }

    /** @param string[] $signFieldIds */
    public function deleteSignFields(string $signFlowId, array $signFieldIds): array
    {
        if ($signFieldIds === []) {
            throw new InvalidArgumentException('At least one sign field ID is required.');
        }
        foreach ($signFieldIds as $id) {
            if (!is_string($id) || trim($id) === '' || strpos($id, ',') !== false) {
                throw new InvalidArgumentException('Sign field IDs must be nonempty strings without commas.');
            }
        }

        return $this->client->request('DELETE', $this->path($signFlowId, '/signers/sign-fields'), null, [
            'signFieldIds' => implode(',', $signFieldIds),
        ]);
    }

    public function antchainInfo(array $body): array
    {
        return $this->client->request('POST', '/v3/antchain-file-info', $body);
    }

    public function verifyAntchain(array $body): array
    {
        return $this->client->request('POST', '/v3/antchain-file-info/verify', $body);
    }

    public function rescissionUrl(string $signFlowId, array $body): array
    {
        return $this->client->request('POST', $this->path($signFlowId, '/rescission-url'), $body);
    }

    private function path(string $signFlowId, string $suffix): string
    {
        return '/v3/sign-flow/' . $this->segment($signFlowId) . $suffix;
    }
}
