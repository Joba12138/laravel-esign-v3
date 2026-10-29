<?php

namespace Joba\Esign\Tests;

use InvalidArgumentException;
use Joba\Esign\Client;
use PHPUnit\Framework\TestCase;

class ResourceTest extends TestCase
{
    /** @dataProvider endpointProvider */
    public function testModuleMapsToTheV3EndpointAndPreservesTheEnvelope(
        string $module,
        string $method,
        array $arguments,
        string $httpMethod,
        string $path,
        ?array $body = null,
        array $query = []
    ): void {
        $envelope = ['code' => 0, 'message' => 'ok', 'data' => ['result' => 'unchanged']];
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['request'])->getMock();
        $client->expects($this->once())->method('request')
            ->with($httpMethod, $path, $body, $query)->willReturn($envelope);

        $this->assertSame($envelope, $client->{$module}()->{$method}(...$arguments));
    }

    public function endpointProvider(): array
    {
        $body = ['customBizNum' => 'biz-1', 'enabled' => false];
        $query = ['pageNum' => 1, 'pageSize' => 20];

        return [
            'create file upload url' => ['file', 'createUploadUrl', [$body], 'POST', '/v3/files/file-upload-url', $body],
            'create seal image file key' => ['file', 'createFileKey', [$body], 'POST', '/v3/files/file-key', $body],
            'create seal position url' => ['file', 'createSealPositionUrl', [[
                'customBizNum' => 'biz-1', 'fileId' => 'file-1', 'signerRoles' => ['甲方', '乙方'],
            ]], 'POST', '/v3/files/get-seal-position-url', [
                'customBizNum' => 'biz-1', 'fileId' => 'file-1', 'signerRoles' => ['甲方', '乙方'],
            ]],
            'file detail' => ['file', 'detail', ['file-1'], 'GET', '/v3/files/file-1'],
            'file keyword positions' => ['file', 'keywordPositions', ['file-1', ['keywords' => '签字']], 'GET', '/v3/files/file-1/keyword-positions', null, ['keywords' => '签字']],
            'create file from template' => ['file', 'createByTemplate', [$body], 'POST', '/v3/files/create-by-doc-template', $body],
            'verify file' => ['file', 'verify', ['file-1', $body], 'POST', '/v3/files/file-1/verify', $body],
            'template create url' => ['template', 'createUrl', [$body], 'POST', '/v3/doc-templates/doc-template-create-url', $body],
            'template detail' => ['template', 'detail', ['template-1'], 'GET', '/v3/doc-templates/template-1'],
            'search templates' => ['template', 'search', [$query], 'GET', '/v3/doc-templates', null, $query],
            'search templates default query' => ['template', 'search', [], 'GET', '/v3/doc-templates'],
            'template edit url' => ['template', 'editUrl', ['template-1', $body], 'POST', '/v3/doc-templates/template-1/doc-template-edit-url', $body],
            'template edit url default body' => ['template', 'editUrl', ['template-1'], 'POST', '/v3/doc-templates/template-1/doc-template-edit-url', []],
            'template preview url' => ['template', 'previewUrl', [$body], 'POST', '/v3/doc-templates/doc-template-preview-url', $body],
            'create sign flow' => ['signFlow', 'createByFile', [$body], 'POST', '/v3/sign-flow/create-by-file', $body],
            'sign url' => ['signFlow', 'signUrl', ['flow-1', $body], 'POST', '/v3/sign-flow/flow-1/sign-url', $body],
            'batch sign url' => ['signFlow', 'batchSignUrl', [$body], 'POST', '/v3/sign-flow/batch-sign-url', $body],
            'download signed files' => ['signFlow', 'downloadUrls', ['flow-1', $query], 'GET', '/v3/sign-flow/flow-1/file-download-url', null, $query],
            'revoke flow' => ['signFlow', 'revoke', ['flow-1'], 'POST', '/v3/sign-flow/flow-1/revoke'],
            'revoke flow with reason' => ['signFlow', 'revoke', ['flow-1', $body], 'POST', '/v3/sign-flow/flow-1/revoke', $body],
            'urge signers' => ['signFlow', 'urge', ['flow-1', $body], 'POST', '/v3/sign-flow/flow-1/urge', $body],
            'finish flow' => ['signFlow', 'finish', ['flow-1'], 'POST', '/v3/sign-flow/flow-1/finish'],
            'flow detail' => ['signFlow', 'detail', ['flow-1'], 'GET', '/v3/sign-flow/flow-1/detail'],
            'search flows' => ['signFlow', 'search', [$body], 'POST', '/v3/sign-flow/sign-flow-list', $body],
            'add sign fields' => ['signFlow', 'addSignFields', ['flow-1', $body], 'POST', '/v3/sign-flow/flow-1/signers/sign-fields', $body],
            'delete sign fields' => ['signFlow', 'deleteSignFields', ['flow-1', ['field-1', 'field-2']], 'DELETE', '/v3/sign-flow/flow-1/signers/sign-fields', null, ['signFieldIds' => 'field-1,field-2']],
            'antchain file info' => ['signFlow', 'antchainInfo', [$body], 'POST', '/v3/antchain-file-info', $body],
            'antchain verify' => ['signFlow', 'verifyAntchain', [$body], 'POST', '/v3/antchain-file-info/verify', $body],
            'rescission url' => ['signFlow', 'rescissionUrl', ['flow-1', $body], 'POST', '/v3/sign-flow/flow-1/rescission-url', $body],
            'person auth url' => ['identity', 'personAuthUrl', [$body], 'POST', '/v3/psn-auth-url', $body],
            'organization auth url' => ['identity', 'organizationAuthUrl', [$body], 'POST', '/v3/org-auth-url', $body],
            'person identity info' => ['identity', 'personIdentityInfo', [['psnAccount' => 'account-1']], 'GET', '/v3/persons/identity-info', null, ['psnAccount' => 'account-1']],
            'person authorized info' => ['identity', 'personAuthorizedInfo', ['person-1'], 'GET', '/v3/persons/person-1/authorized-info'],
            'organization identity info' => ['identity', 'organizationIdentityInfo', [['orgName' => '组织']], 'GET', '/v3/organizations/identity-info', null, ['orgName' => '组织']],
            'organization authorized info' => ['identity', 'organizationAuthorizedInfo', ['org-1'], 'GET', '/v3/organizations/org-1/authorized-info'],
            'organization members' => ['organization', 'members', ['org-1', $query], 'GET', '/v3/organizations/org-1/member-list', null, $query],
            'organization administrators' => ['organization', 'administrators', ['org-1'], 'GET', '/v3/organizations/org-1/administrators'],
            'add organization members' => ['organization', 'addMembers', ['org-1', $body], 'POST', '/v3/organizations/org-1/members', $body],
            'create personal seal from template' => ['seal', 'createPersonalByTemplate', [$body], 'POST', '/v3/seals/psn-seals/create-by-template', $body],
            'personal seals' => ['seal', 'personalList', [$query], 'GET', '/v3/seals/psn-seal-list', null, $query],
            'personal seal detail' => ['seal', 'personalDetail', [['psnId' => 'person-1', 'sealId' => 'seal-1']], 'GET', '/v3/seals/psn-seal', null, ['psnId' => 'person-1', 'sealId' => 'seal-1']],
            'manage personal seals url' => ['seal', 'personalManageUrl', [$body], 'POST', '/v3/seals/psn-seals-manage-url', $body],
            'create personal seal url' => ['seal', 'personalCreateUrl', [$body], 'POST', '/v3/seals/psn-seal-create-url', $body],
            'create personal seal from image' => ['seal', 'createPersonalByImage', [$body], 'POST', '/v3/seals/psn-seals/create-by-image', $body],
            'create organization seal' => ['seal', 'createOrganizationByTemplate', [$body], 'POST', '/v3/seals/org-seals/create-by-template', $body],
            'organization own seals' => ['seal', 'organizationOwnList', [$query], 'GET', '/v3/seals/org-own-seal-list', null, $query],
            'authorize internal seal' => ['seal', 'authorizeInternal', [$body], 'POST', '/v3/seals/org-seals/internal-auth', $body],
            'internal seal authorizations' => ['seal', 'internalAuthorizations', [$query], 'GET', '/v3/seals/org-seals/internal-auth', null, $query],
            'revoke seal authorization' => ['seal', 'revokeAuthorization', [$body], 'POST', '/v3/seals/org-seals/auth-delete', $body],
            'authorize external seal' => ['seal', 'authorizeExternal', [$body], 'POST', '/v3/seals/org-seals/external-auth', $body],
            'organization authorized seals' => ['seal', 'organizationAuthorizedList', [$query], 'GET', '/v3/seals/org-authorized-seal-list', null, $query],
            'place order url' => ['order', 'placeOrderUrl', [$body], 'POST', '/v3/orders/org-place-order-url', $body],
            'remaining quantity' => ['order', 'remainingQuantity', [$query], 'GET', '/v3/orders/remaining-quantity', null, $query],
            'search orders' => ['order', 'search', [$query], 'GET', '/v3/orders/order-list', null, $query],
            'manage order url' => ['order', 'manageUrl', [$body], 'POST', '/v3/orders/org-order-manage-url', $body],
            'resource id is one encoded segment' => ['signFlow', 'detail', ['flow/a b'], 'GET', '/v3/sign-flow/flow%2Fa%20b/detail'],
        ];
    }

    public function testEmptyResourceIdsFailBeforeSendingARequest(): void
    {
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['request'])->getMock();
        $client->expects($this->never())->method('request');
        $this->expectException(InvalidArgumentException::class);

        $client->file()->detail('  ');
    }

    public function testAnEmptySignFieldListCannotDeleteUnspecifiedFields(): void
    {
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['request'])->getMock();
        $client->expects($this->never())->method('request');
        $this->expectException(InvalidArgumentException::class);

        $client->signFlow()->deleteSignFields('flow-1', []);
    }
}
