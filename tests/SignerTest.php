<?php

namespace Joba\Esign\Tests;

use InvalidArgumentException;
use Joba\Esign\Signer;
use PHPUnit\Framework\TestCase;

class SignerTest extends TestCase
{
    public function testGetSignatureMatchesIndependentVector(): void
    {
        $signer = new Signer('test-app', 'test-secret');

        $this->assertSame(
            'bQY+S+8pkpT0gY6VwAa8kcHywOhJcXwIroQ59a1YrXQ=',
            $signer->signature('GET', '/v3/files/file-1')
        );
    }

    public function testPostSignatureUsesExactUtf8JsonBytes(): void
    {
        $signer = new Signer('test-app', 'test-secret');
        $body = '{"name":"张三/合同","enabled":false,"zero":0}';
        $headers = $signer->headers('POST', '/v3/sign-flow/create-by-file', $body, '1700000000123');

        $this->assertSame('mfyWV3x3dZnP6p8+xQOiCQ==', $headers['Content-MD5']);
        $this->assertSame('nhnDJ+nl5bF5Ar3VMjt1q806cjhdOFVj8mWy2B3ixHU=', $headers['X-Tsign-Open-Ca-Signature']);
        $this->assertSame('test-app', $headers['X-Tsign-Open-App-Id']);
        $this->assertSame('test-app', $headers['X-Tsign-Dns-App-Id']);
        $this->assertSame('1700000000123', $headers['X-Tsign-Open-Ca-Timestamp']);
        $this->assertSame('Signature', $headers['X-Tsign-Open-Auth-Mode']);
        $this->assertSame('*/*', $headers['Accept']);
        $this->assertSame('application/json; charset=UTF-8', $headers['Content-Type']);
    }

    public function testQuerySortingPreservesZeroFalseAndSpecialCharacters(): void
    {
        $signer = new Signer('test-app', 'test-secret');
        $query = ['z' => 'a+b', 'null' => null, 'enabled' => false, 'a' => '张 三'];

        $this->assertSame('a=张 三&enabled=0&z=a+b', $signer->canonicalQuery($query));
        $this->assertSame('a=%E5%BC%A0%20%E4%B8%89&enabled=0&z=a%2Bb', $signer->encodedQuery($query));
        $this->assertSame('empty&zero=0', $signer->canonicalQuery(['zero' => 0, 'empty' => '']));
        $this->assertSame('empty=&zero=0', $signer->encodedQuery(['zero' => 0, 'empty' => '']));
    }

    public function testBodylessRequestsDoNotSendMd5OfAnEmptyString(): void
    {
        $signer = new Signer('test-app', 'test-secret');
        $headers = $signer->headers('GET', '/v3/files/file-1', '', '1700000000123');

        $this->assertArrayNotHasKey('Content-MD5', $headers);
    }

    public function testEmptyPostUsesTheDigestOfItsZeroByteBody(): void
    {
        $signer = new Signer('test-app', 'test-secret');
        $headers = $signer->headers('POST', '/v3/sign-flow/flow-1/finish', '', '1700000000123');

        $this->assertSame('1B2M2Y8AsgTpgAmY7PhCfg==', $headers['Content-MD5']);
        $this->assertSame('cEXWGQFpg2Luv5V7ktTONuczUgyKYynH9j61XQAedU4=',
            $headers['X-Tsign-Open-Ca-Signature']);
    }

    public function testBodylessDeleteOmitsMd5AndMatchesItsIndependentSignatureVector(): void
    {
        $signer = new Signer('test-app', 'test-secret');
        $headers = $signer->headers('DELETE',
            '/v3/sign-flow/flow-1/signers/sign-fields?signFieldIds=field-1', '', '1700000000123');

        $this->assertArrayNotHasKey('Content-MD5', $headers);
        $this->assertSame('onFzIsayH2c7MhTM+SpIQjDypXuQEYN3yFzKYd5gNxs=',
            $headers['X-Tsign-Open-Ca-Signature']);
    }

    public function testNestedQueryValuesAreRejectedInsteadOfBeingSignedDifferently(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Signer('test-app', 'test-secret'))->canonicalQuery(['ids' => ['one', 'two']]);
    }
}
