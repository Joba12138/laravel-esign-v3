<?php

namespace Joba\Esign\Resources;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

class File extends Resource
{
    public function createUploadUrl(array $body): array
    {
        return $this->client->request('POST', '/v3/files/file-upload-url', $body);
    }

    public function createFileKey(array $body): array
    {
        return $this->client->request('POST', '/v3/files/file-key', $body);
    }

    /**
     * Create the drag-and-drop seal positioning page URL.
     * The URL is returned in data.getSealPositionUrl; positions arrive by callback.
     * @see https://open.esign.cn/doc/opendoc/pdf-sign3/gw0r0w5qb5cx2ft6
     */
    public function createSealPositionUrl(array $body): array
    {
        return $this->client->request('POST', '/v3/files/get-seal-position-url', $body);
    }

    public function detail(string $fileId): array
    {
        return $this->client->request('GET', '/v3/files/' . $this->segment($fileId));
    }

    public function keywordPositions(string $fileId, array $query): array
    {
        return $this->client->request('GET', '/v3/files/' . $this->segment($fileId)
            . '/keyword-positions', null, $query);
    }

    public function createByTemplate(array $body): array
    {
        return $this->client->request('POST', '/v3/files/create-by-doc-template', $body);
    }

    public function verify(string $fileId, array $body): array
    {
        return $this->client->request('POST', '/v3/files/' . $this->segment($fileId) . '/verify', $body);
    }

    /**
     * Upload a local document and return the original API envelope containing its fileId.
     * File conversion remains asynchronous; use detail() to inspect its status.
     */
    public function upload(
        string $localPath,
        ?string $fileName = null,
        bool $convertToPdf = false,
        string $contentType = 'application/pdf'
    ): array {
        $body = $this->metadata($localPath, $fileName, $contentType);
        $body['convertToPDF'] = $convertToPdf;
        $response = $this->createUploadUrl($body);
        $this->uploadContent($response, $localPath, $contentType);

        return $response;
    }

    /** Upload a local seal image and return the envelope containing its fileKey. */
    public function uploadSealImage(
        string $localPath,
        ?string $fileName = null,
        string $contentType = 'application/octet-stream'
    ): array {
        $body = $this->metadata($localPath, $fileName, $contentType);
        $response = $this->createFileKey($body);
        $this->uploadContent($response, $localPath, $contentType);

        return $response;
    }

    private function metadata(string $localPath, ?string $fileName, string $contentType): array
    {
        if ($localPath === '' || strpos($localPath, "\0") !== false || strpos($localPath, '://') !== false
            || !is_file($localPath) || !is_readable($localPath)) {
            throw new InvalidArgumentException('Upload requires a readable local regular file.');
        }

        if (trim($contentType) === '' || preg_match('/[\r\n]/', $contentType)) {
            throw new InvalidArgumentException('Invalid upload content type.');
        }

        $fileName = $fileName ?? basename($localPath);
        if (trim($fileName) === '') {
            throw new InvalidArgumentException('File name must not be empty.');
        }

        $size = filesize($localPath);
        if ($size === false || $size <= 0) {
            throw new InvalidArgumentException('Upload file must not be empty.');
        }

        $md5 = md5_file($localPath, true);
        if ($md5 === false) {
            throw new RuntimeException('Unable to calculate upload file checksum.');
        }

        return [
            'contentMd5' => base64_encode($md5),
            'contentType' => $contentType,
            'fileName' => $fileName,
            'fileSize' => $size,
        ];
    }

    private function uploadContent(array $response, string $localPath, string $contentType): void
    {
        $url = $response['data']['fileUploadUrl'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            throw new UnexpectedValueException('Upload response is missing data.fileUploadUrl.');
        }

        $this->client->upload($url, $localPath, $contentType);
    }
}
