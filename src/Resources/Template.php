<?php

namespace Joba\Esign\Resources;

class Template extends Resource
{
    /** @see https://open.esign.cn/doc/opendoc/pdf-sign3/mghz1g */
    public function search(array $query = []): array
    {
        return $this->client->request('GET', '/v3/doc-templates', null, $query);
    }

    public function createUrl(array $body): array
    {
        return $this->client->request('POST', '/v3/doc-templates/doc-template-create-url', $body);
    }

    public function detail(string $docTemplateId): array
    {
        return $this->client->request('GET', '/v3/doc-templates/' . $this->segment($docTemplateId));
    }

    /** @see https://open.esign.cn/doc/opendoc/pdf-sign3/lgb2go */
    public function editUrl(string $docTemplateId, array $body = []): array
    {
        return $this->client->request('POST', '/v3/doc-templates/' . $this->segment($docTemplateId)
            . '/doc-template-edit-url', $body);
    }

    /** @see https://open.esign.cn/doc/opendoc/pdf-sign3/le6t3e7fbsrdmtx6 */
    public function previewUrl(array $body): array
    {
        return $this->client->request('POST', '/v3/doc-templates/doc-template-preview-url', $body);
    }
}
