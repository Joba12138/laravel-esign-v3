<?php

namespace Joba\Esign\Tests;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Joba\Esign\Client;
use Joba\Esign\Exceptions\EsignException;
use Joba\Esign\Exceptions\InvalidResponseException;
use Joba\Esign\Exceptions\TransportException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class ClientTest extends TestCase
{
    private array $history = [];
    private array $requestBodies = [];

    private function client(array $queue, array $config = [], array $httpDefaults = []): Client
    {
        $this->history = [];
        $this->requestBodies = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->requestBodies[] = (string) $request->getBody();
            return $request;
        }));

        return new Client(array_merge([
            'app_id' => 'test-app', 'app_secret' => 'test-secret', 'sandbox' => true,
        ], $config), new HttpClient(array_merge($httpDefaults, ['handler' => $stack])));
    }

    public function testPostSerializesOnlyOnceAndSignsExactlyWhatIsSent(): void
    {
        $response = ['code' => 0, 'message' => '成功', 'data' => ['signFlowId' => 'flow-1']];
        $client = $this->client([new Response(200, [], json_encode($response))]);

        $this->assertSame($response, $client->request('POST', '/v3/sign-flow/create-by-file', [
            'name' => '张三/合同', 'enabled' => false, 'zero' => 0,
        ]));

        $request = $this->history[0]['request'];
        $this->assertSame('{"name":"张三/合同","enabled":false,"zero":0}', $this->requestBodies[0]);
        $this->assertSame('mfyWV3x3dZnP6p8+xQOiCQ==', $request->getHeaderLine('Content-MD5'));
        $this->assertSame('nhnDJ+nl5bF5Ar3VMjt1q806cjhdOFVj8mWy2B3ixHU=', $request->getHeaderLine('X-Tsign-Open-Ca-Signature'));
        $this->assertSame('smlopenapi.esign.cn', $request->getUri()->getHost());
        $this->assertTrue($this->history[0]['options']['verify']);
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testSealPositionUrlPreservesItsBusinessReferenceRolesAndResponseField(): void
    {
        $response = [
            'code' => 0,
            'message' => '成功',
            'data' => ['getSealPositionUrl' => 'https://esign.example.test/positions?token=example'],
        ];
        $client = $this->client([new Response(200, [], json_encode($response))]);

        $result = $client->file()->createSealPositionUrl([
            'customBizNum' => '合同-20261027',
            'fileId' => 'file-1',
            'signerRoles' => ['甲方', '乙方'],
            'clientType' => 'PC',
            'urlType' => 2,
            'redirectUrl' => 'https://example.test/esign/positions',
        ]);

        $this->assertSame($response, $result);
        $this->assertSame('https://esign.example.test/positions?token=example', $result['data']['getSealPositionUrl']);
        $this->assertSame('{"customBizNum":"合同-20261027","fileId":"file-1","signerRoles":["甲方","乙方"],"clientType":"PC","urlType":2,"redirectUrl":"https://example.test/esign/positions"}',
            $this->requestBodies[0]);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v3/files/get-seal-position-url', $request->getUri()->getPath());
        $this->assertSame('zeCNARlRMerrHGcQR04l4Q==', $request->getHeaderLine('Content-MD5'));
        $this->assertSame('dlwTUzawdnK0CoVMIJVHIodFOIDLgSAWZSFqj4vPEvY=',
            $request->getHeaderLine('X-Tsign-Open-Ca-Signature'));
        $this->assertArrayNotHasKey('signFlowId', json_decode($this->requestBodies[0], true));
        $this->assertCount(1, $this->history);
    }

    public function testQuerySignatureAndEncodedTransportUseTheSameSortedParameters(): void
    {
        $client = $this->client([new Response(200, [], '{"code":0,"data":null}')]);

        $client->request('GET', '/v3/persons/identity-info', null, [
            'z' => 'a+b', 'null' => null, 'enabled' => false, 'a' => '张 三',
        ]);

        $request = $this->history[0]['request'];
        $this->assertSame('a=%E5%BC%A0%20%E4%B8%89&enabled=0&z=a%2Bb', $request->getUri()->getQuery());
        $this->assertSame('dPTUitW52HObXpQTzOfME0X8ZZQ14Mg/wTZ0J9Jso/4=', $request->getHeaderLine('X-Tsign-Open-Ca-Signature'));
        $this->assertSame('', $this->requestBodies[0]);
        $this->assertFalse($request->hasHeader('Content-MD5'));
    }

    public function testInjectedDefaultJsonAndQueryCannotReplaceTheSignedPayload(): void
    {
        $client = $this->client([new Response(200, [], '{"code":0,"data":null}')], [], [
            'json' => ['injected' => true],
            'query' => ['injected' => '1'],
        ]);
        $client->request('POST', '/v3/sign-flow/create-by-file', ['name' => '张三/合同'], ['customBizNum' => 'biz-1']);

        $request = $this->history[0]['request'];
        $this->assertSame('{"name":"张三/合同"}', $this->requestBodies[0]);
        $this->assertSame('customBizNum=biz-1', $request->getUri()->getQuery());
        $this->assertSame(base64_encode(md5($this->requestBodies[0], true)), $request->getHeaderLine('Content-MD5'));
    }

    public function testEmptyQueryValueSignsOnlyItsKeyWhileWireQueryKeepsEquals(): void
    {
        $client = $this->client([new Response(200, [], '{"code":0,"data":null}')]);
        $client->request('GET', '/v3/files/id', null, ['zero' => 0, 'empty' => '']);

        $request = $this->history[0]['request'];
        $this->assertSame('empty=&zero=0', $request->getUri()->getQuery());
        $this->assertSame('ZkABQMn8YoWtlBUxAFXMMDeHLEIoO2aiVGN2Nza/Tek=',
            $request->getHeaderLine('X-Tsign-Open-Ca-Signature'));
    }

    public function testGenericRequestNormalizesPathBeforeSigningIt(): void
    {
        $client = $this->client([new Response(200, [], '{"code":0,"data":null}')]);
        $client->request('GET', '/v3/files/张三');

        $request = $this->history[0]['request'];
        $this->assertSame('/v3/files/%E5%BC%A0%E4%B8%89', $request->getUri()->getPath());
        $this->assertSame('ewcdcxLBG50oED0mnbtXaMGsPgUVX8Lc7IoFqBeNoCo=',
            $request->getHeaderLine('X-Tsign-Open-Ca-Signature'));
    }

    /** @dataProvider injectedBodyDefaultsProvider */
    public function testInjectedBodyDefaultsCannotOverrideExplicitJson(array $defaults): void
    {
        $client = $this->client([new Response(200, [], '{"code":0,"data":null}')], [], $defaults);
        $client->request('POST', '/v3/sign-flow/create-by-file', ['name' => '签名']);

        $this->assertSame('{"name":"签名"}', $this->requestBodies[0]);
        $this->assertSame('application/json; charset=UTF-8', $this->history[0]['request']->getHeaderLine('Content-Type'));
    }

    public function injectedBodyDefaultsProvider(): array
    {
        return [
            'body' => [['body' => 'injected raw body']],
            'form params' => [['form_params' => ['injected' => '1']]],
            'multipart' => [['multipart' => [['name' => 'injected', 'contents' => '1']]]],
        ];
    }

    public function testInjectedSinkDoesNotWritePrivateApiResponsesToAnUnexpectedFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'esign-sink-');
        file_put_contents($path, 'untouched');
        $client = $this->client([new Response(200, [], '{"code":0,"data":{"private":"value"}}')], [], ['sink' => $path]);

        try {
            $client->request('GET', '/v3/files/file-1');
            $this->assertSame('untouched', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testStringZeroSuccessPreservesTheOriginalEnvelopeAndNullData(): void
    {
        $client = $this->client([new Response(200, [], '{"code":"0","message":"ok","data":null}')]);

        $this->assertSame(['code' => '0', 'message' => 'ok', 'data' => null], $client->request('GET', '/v3/files/id'));
    }

    public function testBusinessFailureIsNotMistakenForHttpSuccess(): void
    {
        $client = $this->client([new Response(200, [], '{"code":100001,"message":"permission denied","data":null}')]);

        try {
            $client->request('POST', '/v3/sign-flow/create-by-file', ['docs' => []]);
            $this->fail('A nonzero business code must throw.');
        } catch (EsignException $exception) {
            $this->assertSame(100001, $exception->getBusinessCode());
            $this->assertSame(200, $exception->getHttpStatus());
            $this->assertSame(['code' => 100001, 'message' => 'permission denied', 'data' => null], $exception->getResponseData());
            $this->assertStringContainsString('permission denied', $exception->getMessage());
        }
    }

    public function testHttpErrorsAreRejectedEvenWhenTheirBodyClaimsSuccess(): void
    {
        $client = $this->client([new Response(503, [], '{"code":0,"message":"upstream unavailable","data":null}')]);

        try {
            $client->request('GET', '/v3/files/id');
            $this->fail('An unsuccessful HTTP status must throw.');
        } catch (EsignException $exception) {
            $this->assertSame(503, $exception->getHttpStatus());
        }
    }

    public function testHttpFailurePreservesRequestIdAndParsedBusinessError(): void
    {
        $client = $this->client([new Response(401, ['X-Tsign-Open-Ca-Request-Id' => 'request-123'],
            '{"code":"unauthorized","message":"invalid credentials"}')]);

        try {
            $client->request('GET', '/v3/files/id');
            $this->fail('HTTP failure must throw.');
        } catch (EsignException $exception) {
            $this->assertSame(401, $exception->getHttpStatus());
            $this->assertSame('unauthorized', $exception->getBusinessCode());
            $this->assertSame('request-123', $exception->getRequestId());
        }
    }

    public function testGenericDeleteBodyHasMatchingMd5InsteadOfSilentlyDroppingItsBody(): void
    {
        $client = $this->client([new Response(200, [], '{"code":0,"data":null}')]);
        $client->request('DELETE', '/v3/organizations/org-1/members', ['memberPsnIds' => ['p-1', 'p-2']]);

        $this->assertSame('{"memberPsnIds":["p-1","p-2"]}', $this->requestBodies[0]);
        $this->assertSame(base64_encode(md5($this->requestBodies[0], true)),
            $this->history[0]['request']->getHeaderLine('Content-MD5'));
    }

    public function testFinishSignsAndSendsAnEmptyPostBodyInsteadOfAJsonObject(): void
    {
        $client = $this->client([new Response(200, [], '{"code":0,"data":null}')]);
        $client->signFlow()->finish('flow-1');

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v3/sign-flow/flow-1/finish', $request->getUri()->getPath());
        $this->assertSame('', $this->requestBodies[0]);
        $this->assertSame(0, $request->getBody()->getSize());
        $this->assertSame('1B2M2Y8AsgTpgAmY7PhCfg==', $request->getHeaderLine('Content-MD5'));
        $this->assertSame('cEXWGQFpg2Luv5V7ktTONuczUgyKYynH9j61XQAedU4=',
            $request->getHeaderLine('X-Tsign-Open-Ca-Signature'));
    }

    /** @dataProvider malformedResponseProvider */
    public function testMalformedResponsesFailClosed(string $body): void
    {
        $client = $this->client([new Response(200, [], $body)]);
        $this->expectException(InvalidResponseException::class);

        $client->request('GET', '/v3/files/id');
    }

    public function malformedResponseProvider(): array
    {
        return [
            'html' => ['<html>gateway error</html>'],
            'invalid json' => ['{"code":'],
            'missing code' => ['{"message":"ok","data":{}}'],
            'json null' => ['null'],
            'json list' => ['[0,"ok"]'],
            'boolean code' => ['{"code":false,"data":null}'],
        ];
    }

    public function testAConnectionFailureDoesNotRetryCreatingASignFlow(): void
    {
        $failure = new ConnectException('Connection timed out', new Request('POST', 'https://smlopenapi.esign.cn/v3/sign-flow/create-by-file'));
        $client = $this->client([$failure, new Response(200, [], '{"code":0,"data":{"signFlowId":"duplicate"}}')]);

        try {
            $client->signFlow()->createByFile(['docs' => [['fileId' => 'file-1']]]);
            $this->fail('A transport failure must throw.');
        } catch (TransportException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
            $this->assertCount(1, $this->history);
        }
    }

    public function testProductionEnvironmentUsesTheProductionHost(): void
    {
        $client = $this->client([new Response(200, [], '{"code":0,"data":{}}')], ['sandbox' => false]);
        $client->file()->detail('file-1');

        $this->assertSame('openapi.esign.cn', $this->history[0]['request']->getUri()->getHost());
    }

    public function testBlankOptionalBaseUrlStillSelectsTheConfiguredEnvironment(): void
    {
        $client = $this->client([new Response(200, [], '{"code":0,"data":{}}')], [
            'sandbox' => false, 'base_url' => '',
        ]);
        $client->file()->detail('file-1');

        $this->assertSame('openapi.esign.cn', $this->history[0]['request']->getUri()->getHost());
    }

    public function testAnAbsoluteApiUrlCannotSendCredentialsToAnotherHost(): void
    {
        $client = $this->client([]);
        $this->expectException(InvalidArgumentException::class);

        $client->request('GET', 'https://attacker.example.test/v3/files/id');
    }

    public function testModuleMethodsAndPropertiesShareTheSameInstances(): void
    {
        $client = $this->client([]);

        $this->assertSame($client->file(), $client->file);
        $this->assertSame($client->signFlow(), $client->signFlow);
        $this->assertSame($client->signFlow(), $client->signflow);
        $this->assertSame($client->callback(), $client->callback());
    }

    public function testUploadSendsExactBinaryDataWithoutApiCredentialsToStorage(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'esign-test-');
        file_put_contents($path, "%PDF-test\0\xff\n");
        $envelope = [
            'code' => 0, 'message' => 'ok',
            'data' => ['fileId' => 'file-1', 'fileUploadUrl' => 'https://storage.example.test/upload?token=unsigned'],
        ];
        $client = $this->client([new Response(200, [], json_encode($envelope)), new Response(200)]);

        try {
            $this->assertSame($envelope, $client->file()->upload($path, '合同.pdf'));
        } finally {
            unlink($path);
        }

        $this->assertCount(2, $this->history);
        $metadata = json_decode($this->requestBodies[0], true);
        $this->assertSame('RG9FdVelcVIqP9+TbzuRKg==', $metadata['contentMd5']);
        $this->assertSame(12, $metadata['fileSize']);
        $this->assertSame('合同.pdf', $metadata['fileName']);
        $this->assertFalse($metadata['convertToPDF']);

        $upload = $this->history[1]['request'];
        $this->assertSame('PUT', $upload->getMethod());
        $this->assertSame('storage.example.test', $upload->getUri()->getHost());
        $this->assertSame('token=unsigned', $upload->getUri()->getQuery());
        $this->assertSame("%PDF-test\0\xff\n", $this->requestBodies[1]);
        $this->assertSame('application/pdf', $upload->getHeaderLine('Content-Type'));
        $this->assertSame('RG9FdVelcVIqP9+TbzuRKg==', $upload->getHeaderLine('Content-MD5'));
        foreach (array_keys($upload->getHeaders()) as $header) {
            $this->assertStringStartsNotWith('x-tsign-', strtolower($header));
        }
        $this->assertTrue($this->history[1]['options']['verify']);
        $this->assertFalse($this->history[1]['options']['allow_redirects']);
    }

    public function testUploadFailureNeverReturnsAUsableFileId(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'esign-test-');
        file_put_contents($path, '%PDF-test');
        $client = $this->client([
            new Response(200, [], '{"code":0,"data":{"fileId":"file-1","fileUploadUrl":"https://storage.example.test/upload"}}'),
            new Response(403, [], 'expired upload URL'),
        ]);

        try {
            $this->expectException(EsignException::class);
            $client->file()->upload($path);
        } finally {
            unlink($path);
        }
    }

    public function testUploadStripsInjectedGuzzleAuthorizationCookiesAndEsignDefaults(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'esign-test-');
        file_put_contents($path, '%PDF-test');
        $client = $this->client([new Response(200)], [], [
            'headers' => [
                'Authorization' => 'Bearer should-not-leak',
                'x-tsign-open-ca-signature' => 'should-not-leak',
                'X-Tsign-Open-App-Id' => 'should-not-leak',
                'Cookie' => 'session=should-not-leak',
            ],
            'auth' => ['username', 'password'],
            'json' => ['injected' => true],
            'query' => ['injected' => '1'],
        ]);

        try {
            $client->upload('https://storage.example.test/upload?signature=untouched', $path, 'application/pdf');
        } finally {
            unlink($path);
        }

        $request = $this->history[0]['request'];
        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertFalse($request->hasHeader('Cookie'));
        $this->assertFalse($request->hasHeader('X-Tsign-Open-Ca-Signature'));
        $this->assertFalse($request->hasHeader('X-Tsign-Open-App-Id'));
        $this->assertSame('%PDF-test', $this->requestBodies[0]);
        $this->assertSame('signature=untouched', $request->getUri()->getQuery());
    }

    public function testRemoteUrlsAreRejectedBeforeAnUploadMetadataRequest(): void
    {
        $client = $this->client([]);
        $this->expectException(InvalidArgumentException::class);

        $client->file()->upload('https://example.test/a.pdf');
    }
}
