<?php

namespace Joba\Esign\Tests;

use Illuminate\Http\Request;
use Joba\Esign\Client;
use Joba\Esign\Exceptions\CallbackVerificationException;
use Joba\Esign\Exceptions\InvalidResponseException;
use Joba\Esign\Laravel\Middleware\VerifyEsignCallback;
use Joba\Esign\Laravel\Middleware\VerifyWebhookSignature;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class CallbackMiddlewareTest extends TestCase
{
    private const SECRET = 'test-secret';
    private const TIMESTAMP = '1700000000123';
    private const BODY = '{"action":"SIGN_FLOW_COMPLETE", "signFlowId":" 流程-1 ", "note":null}';

    public function testAuthenticatedPayloadPreservesTheOriginalJsonAndPassesThroughTheHandlerResponse(): void
    {
        $request = $this->signedRequest(self::BODY);
        $expectedResponse = new Response('durably accepted', 202);
        $called = false;

        $response = $this->middleware()->handle($request, function (Request $received) use ($request, $expectedResponse, &$called) {
            $called = true;
            $this->assertSame($request, $received);
            $this->assertSame(self::BODY, $received->getContent());
            $this->assertSame([
                'action' => 'SIGN_FLOW_COMPLETE',
                'signFlowId' => ' 流程-1 ',
                'note' => null,
            ], $received->attributes->get('esign_callback'));

            return $expectedResponse;
        });

        $this->assertTrue($called);
        $this->assertSame($expectedResponse, $response);
    }

    /** @dataProvider invalidHeaders */
    public function testMissingOrInvalidAuthenticationHeadersRejectBeforeTheHandler(string $header, ?string $value): void
    {
        $request = $this->signedRequest(self::BODY);
        if ($value === null) {
            $request->headers->remove($header);
        } else {
            $request->headers->set($header, $value);
        }

        $response = $this->middleware()->handle($request, function () {
            $this->fail('Unauthenticated callbacks must never reach the handler.');
        });

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($request->attributes->has('esign_callback'));
    }

    public function invalidHeaders(): array
    {
        return [
            'missing signature' => ['X-Tsign-Open-Signature', null],
            'malformed signature' => ['X-Tsign-Open-Signature', 'not-a-signature'],
            'wrong signature' => ['X-Tsign-Open-Signature', str_repeat('0', 64)],
            'missing timestamp' => ['X-Tsign-Open-Timestamp', null],
            'timestamp in seconds' => ['X-Tsign-Open-Timestamp', '1700000000'],
        ];
    }

    public function testChangingOnlyWhitespaceInTheSignedBodyRejectsTheCallback(): void
    {
        $request = $this->signedRequest(str_replace(', ', ',', self::BODY));
        $request->headers->set('X-Tsign-Open-Signature', $this->signature(self::BODY));

        $response = $this->middleware()->handle($request, function () {
            $this->fail('A modified body must never reach the handler.');
        });

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($request->attributes->has('esign_callback'));
    }

    /** @dataProvider invalidJsonBodies */
    public function testSignedButInvalidJsonObjectRejectsBeforeTheHandler(string $body): void
    {
        $request = $this->signedRequest($body);

        $response = $this->middleware()->handle($request, function () {
            $this->fail('An invalid callback payload must never reach the handler.');
        });

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($request->attributes->has('esign_callback'));
    }

    public function invalidJsonBodies(): array
    {
        return [
            'malformed JSON' => ['{"action":'],
            'JSON array' => ['[{"action":"SIGN_FLOW_COMPLETE"}]'],
            'JSON string' => ['"SIGN_FLOW_COMPLETE"'],
            'JSON null' => ['null'],
        ];
    }

    public function testAuthenticationUsesTheOriginalQueryAfterGlobalInputMiddlewareChangesTheQueryBag(): void
    {
        $originalQuery = ['b' => '02', 'a' => ' 张三 ', 'empty' => ''];
        $request = $this->signedRequest(self::BODY, $originalQuery);
        // Simulate Laravel TrimStrings and ConvertEmptyStringsToNull.
        $request->query->replace(['b' => '02', 'a' => '张三', 'empty' => null]);
        $expectedResponse = new Response('accepted', 202);

        $response = $this->middleware()->handle($request, function (Request $received) use ($expectedResponse) {
            $this->assertSame(' 流程-1 ', $received->attributes->get('esign_callback')['signFlowId']);

            return $expectedResponse;
        });

        $this->assertSame($expectedResponse, $response);
        $this->assertSame(['b' => '02', 'a' => '张三', 'empty' => null], $request->query->all());
    }

    /** @dataProvider businessExceptionClasses */
    public function testExceptionsFromTheBusinessHandlerAreNotConvertedIntoAuthenticationErrors(string $exceptionClass): void
    {
        $exception = new $exceptionClass('Business handler failed after authentication.');
        $this->expectExceptionObject($exception);

        $this->middleware()->handle($this->signedRequest(self::BODY), function () use ($exception) {
            throw $exception;
        });
    }

    public function businessExceptionClasses(): array
    {
        return [
            [CallbackVerificationException::class],
            [InvalidResponseException::class],
        ];
    }

    public function testLegacyMiddlewareStillAuthenticatesAndRejectsModifiedCallbacks(): void
    {
        $legacy = new VerifyEsignCallback(new Client([
            'app_id' => 'test-app',
            'app_secret' => self::SECRET,
            'sandbox' => true,
        ]));
        $expected = new Response('accepted', 202);

        $response = $legacy->handle($this->signedRequest(self::BODY), function (Request $request) use ($expected) {
            $this->assertSame('SIGN_FLOW_COMPLETE', $request->attributes->get('esign_callback')['action']);

            return $expected;
        });
        $this->assertSame($expected, $response);

        $modified = $this->signedRequest(self::BODY);
        $modified->headers->set('X-Tsign-Open-Signature', str_repeat('0', 64));
        $rejected = $legacy->handle($modified, function () {
            $this->fail('The legacy class must continue to reject unauthenticated callbacks.');
        });
        $this->assertSame(401, $rejected->getStatusCode());
    }

    private function middleware(): VerifyWebhookSignature
    {
        return new VerifyWebhookSignature(new Client([
            'app_id' => 'test-app',
            'app_secret' => self::SECRET,
            'sandbox' => true,
        ]));
    }

    private function signedRequest(string $body, array $query = []): Request
    {
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return Request::create('/esign/callback' . ($queryString === '' ? '' : '?' . $queryString), 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TSIGN_OPEN_SIGNATURE' => $this->signature($body, $query),
            'HTTP_X_TSIGN_OPEN_TIMESTAMP' => self::TIMESTAMP,
        ], $body);
    }

    private function signature(string $body, array $query = []): string
    {
        ksort($query, SORT_STRING);

        return hash_hmac('sha256', self::TIMESTAMP . implode('', $query) . $body, self::SECRET);
    }
}
