<?php

namespace Joba\Esign\Resources;

class Identity extends Resource
{
    public function personAuthUrl(array $body): array
    {
        return $this->client->request('POST', '/v3/psn-auth-url', $body);
    }

    public function organizationAuthUrl(array $body): array
    {
        return $this->client->request('POST', '/v3/org-auth-url', $body);
    }

    public function personIdentityInfo(array $query): array
    {
        return $this->client->request('GET', '/v3/persons/identity-info', null, $query);
    }

    public function personAuthorizedInfo(string $psnId): array
    {
        return $this->client->request('GET', '/v3/persons/' . $this->segment($psnId) . '/authorized-info');
    }

    public function organizationIdentityInfo(array $query): array
    {
        return $this->client->request('GET', '/v3/organizations/identity-info', null, $query);
    }

    public function organizationAuthorizedInfo(string $orgId): array
    {
        return $this->client->request('GET', '/v3/organizations/' . $this->segment($orgId) . '/authorized-info');
    }
}
