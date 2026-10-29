<?php

namespace Joba\Esign\Tests;

use Joba\Esign\CallbackVerifier;
use Joba\Esign\Exceptions\CallbackVerificationException;
use PHPUnit\Framework\TestCase;

class CallbackVerifierTest extends TestCase
{
    private const BODY = '{"action":"SIGN_FLOW_COMPLETE", "signFlowId":"流程-1"}';
    private const TIMESTAMP = '1700000000123';
    private const SIGNATURE = 'ba7ab91fbe04adb02bf0a53b3a6c34f7815730c65f41637c611888413cce2970';

    public function testOfficialCallbackAlgorithmUsesHexDigestAndSortedQueryValues(): void
    {
        $verifier = new CallbackVerifier('test-secret', 300);
        $query = ['b' => '02', 'a' => '张三'];

        $this->assertTrue($verifier->verify(self::BODY, self::SIGNATURE, self::TIMESTAMP, $query, 1700000000123));
        $this->assertSame(
            ['action' => 'SIGN_FLOW_COMPLETE', 'signFlowId' => '流程-1'],
            $verifier->parse(self::BODY, self::SIGNATURE, self::TIMESTAMP, $query, 1700000000123)
        );
    }

    public function testEvenWhitespaceChangesToTheRawBodyFailVerification(): void
    {
        $verifier = new CallbackVerifier('test-secret');

        $this->assertFalse($verifier->verify(
            str_replace(', ', ',', self::BODY), self::SIGNATURE, self::TIMESTAMP,
            ['b' => '02', 'a' => '张三'], 1700000000123
        ));
    }

    public function testMissingOrMalformedSignatureAndTimestampFailClosed(): void
    {
        $verifier = new CallbackVerifier('test-secret');

        foreach ([['', self::TIMESTAMP], ['not-a-signature', self::TIMESTAMP], [self::SIGNATURE, ''],
            [self::SIGNATURE, '1700000000'], [self::SIGNATURE, '170000000012x']] as $values) {
            $this->assertFalse($verifier->verify(self::BODY, $values[0], $values[1], ['b' => '02', 'a' => '张三'], 1700000000123));
        }
    }

    public function testExpiredAndFutureCallbacksAreRejected(): void
    {
        $verifier = new CallbackVerifier('test-secret', 300);
        $query = ['b' => '02', 'a' => '张三'];

        $this->assertFalse($verifier->verify(self::BODY, self::SIGNATURE, self::TIMESTAMP, $query, 1700000300124));
        $this->assertFalse($verifier->verify(self::BODY, self::SIGNATURE, self::TIMESTAMP, $query, 1699999700122));
    }

    public function testDisablingTimeWindowDoesNotDisableSignatureVerification(): void
    {
        $verifier = new CallbackVerifier('test-secret', 0);
        $query = ['b' => '02', 'a' => '张三'];

        $this->assertTrue($verifier->verify(self::BODY, self::SIGNATURE, self::TIMESTAMP, $query, 1800000000000));
        $this->assertFalse($verifier->verify(self::BODY . ' ', self::SIGNATURE, self::TIMESTAMP, $query, 1800000000000));
    }

    public function testDefaultAcceptsASignedDelayedRetryWithoutDisablingAuthentication(): void
    {
        $verifier = new CallbackVerifier('test-secret');
        $query = ['b' => '02', 'a' => '张三'];

        $this->assertTrue($verifier->verify(self::BODY, self::SIGNATURE, self::TIMESTAMP, $query, 1800000000000));
        $this->assertFalse($verifier->verify(self::BODY . ' ', self::SIGNATURE, self::TIMESTAMP, $query, 1800000000000));
    }

    public function testSealPositionCallbackPreservesBusinessReferenceAndComponentsAfterAuthentication(): void
    {
        $body = '{"action":"GET_SEAL_POSITION","customBizNum":"合同-20261027","timestamp":1700000000000,"components":[{"fileId":"file-1","signerRole":"甲方签署区","componentType":6,"componentPosition":{"componentPageNum":1,"componentPositionX":100,"componentPositionY":200},"componentSize":{"componentWidth":100,"componentHeight":100},"normalSignField":{"signFieldStyle":1,"sealSpecs":1,"showSignDate":0}}]}';
        $signature = '15520ac45eda7466817e728bafe7e3ba7ba703ec3231ce01209ead82bda02db3';
        $verifier = new CallbackVerifier('test-secret');

        $payload = $verifier->parse($body, $signature, '1700000000123', [], 1800000000000);

        $this->assertSame('GET_SEAL_POSITION', $payload['action']);
        $this->assertSame('合同-20261027', $payload['customBizNum']);
        $this->assertSame(1700000000000, $payload['timestamp']);
        $this->assertSame([[
            'fileId' => 'file-1',
            'signerRole' => '甲方签署区',
            'componentType' => 6,
            'componentPosition' => [
                'componentPageNum' => 1,
                'componentPositionX' => 100,
                'componentPositionY' => 200,
            ],
            'componentSize' => ['componentWidth' => 100, 'componentHeight' => 100],
            'normalSignField' => ['signFieldStyle' => 1, 'sealSpecs' => 1, 'showSignDate' => 0],
        ]], $payload['components']);
        $this->assertArrayNotHasKey('data', $payload);
        $this->assertArrayNotHasKey('signFlowId', $payload);
    }

    public function testParseNeverReturnsAnUnauthenticatedEvent(): void
    {
        $this->expectException(CallbackVerificationException::class);

        (new CallbackVerifier('test-secret'))->parse(self::BODY, str_repeat('0', 64), self::TIMESTAMP, [], 1700000000123);
    }
}
