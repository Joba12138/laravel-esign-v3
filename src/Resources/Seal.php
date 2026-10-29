<?php

namespace Joba\Esign\Resources;

class Seal extends Resource
{
    public function createPersonalByTemplate(array $body): array
    {
        return $this->client->request('POST', '/v3/seals/psn-seals/create-by-template', $body);
    }

    public function personalList(array $query): array
    {
        return $this->client->request('GET', '/v3/seals/psn-seal-list', null, $query);
    }

    public function personalDetail(array $query): array
    {
        return $this->client->request('GET', '/v3/seals/psn-seal', null, $query);
    }

    public function personalManageUrl(array $body): array
    {
        return $this->client->request('POST', '/v3/seals/psn-seals-manage-url', $body);
    }

    public function personalCreateUrl(array $body): array
    {
        return $this->client->request('POST', '/v3/seals/psn-seal-create-url', $body);
    }

    public function createPersonalByImage(array $body): array
    {
        return $this->client->request('POST', '/v3/seals/psn-seals/create-by-image', $body);
    }

    public function createOrganizationByTemplate(array $body): array
    {
        return $this->client->request('POST', '/v3/seals/org-seals/create-by-template', $body);
    }

    public function organizationOwnList(array $query): array
    {
        return $this->client->request('GET', '/v3/seals/org-own-seal-list', null, $query);
    }

    public function authorizeInternal(array $body): array
    {
        return $this->client->request('POST', '/v3/seals/org-seals/internal-auth', $body);
    }

    public function internalAuthorizations(array $query): array
    {
        return $this->client->request('GET', '/v3/seals/org-seals/internal-auth', null, $query);
    }

    public function revokeAuthorization(array $body): array
    {
        return $this->client->request('POST', '/v3/seals/org-seals/auth-delete', $body);
    }

    public function authorizeExternal(array $body): array
    {
        return $this->client->request('POST', '/v3/seals/org-seals/external-auth', $body);
    }

    public function organizationAuthorizedList(array $query): array
    {
        return $this->client->request('GET', '/v3/seals/org-authorized-seal-list', null, $query);
    }
}
