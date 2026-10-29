<?php

namespace Joba\Esign\Resources;

class Organization extends Resource
{
    public function members(string $orgId, array $query = []): array
    {
        return $this->client->request('GET', $this->path($orgId, '/member-list'), null, $query);
    }

    public function administrators(string $orgId): array
    {
        return $this->client->request('GET', $this->path($orgId, '/administrators'));
    }

    public function addMembers(string $orgId, array $body): array
    {
        return $this->client->request('POST', $this->path($orgId, '/members'), $body);
    }

    private function path(string $orgId, string $suffix): string
    {
        return '/v3/organizations/' . $this->segment($orgId) . $suffix;
    }
}
