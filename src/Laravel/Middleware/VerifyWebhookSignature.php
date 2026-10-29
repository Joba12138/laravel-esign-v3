<?php

namespace Joba\Esign\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Joba\Esign\Client;
use Joba\Esign\Exceptions\CallbackVerificationException;
use Joba\Esign\Exceptions\InvalidResponseException;
use Symfony\Component\HttpFoundation\JsonResponse;

class VerifyWebhookSignature
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function handle(Request $request, Closure $next)
    {
        // Laravel's global input middleware may trim or null query values.
        // Signature verification must use the original query and body bytes.
        $query = [];
        parse_str((string) $request->server->get('QUERY_STRING', ''), $query);

        try {
            $payload = $this->client->callback()->parse(
                $request->getContent(),
                (string) $request->header('X-Tsign-Open-Signature', ''),
                (string) $request->header('X-Tsign-Open-Timestamp', ''),
                $query
            );
        } catch (CallbackVerificationException $exception) {
            return new JsonResponse(['message' => 'Invalid E签宝 callback signature.'], 401);
        } catch (InvalidResponseException $exception) {
            return new JsonResponse(['message' => 'Invalid E签宝 callback JSON body.'], 400);
        }

        $request->attributes->set('esign_callback', $payload);

        // Only the business handler can acknowledge durable receipt.
        return $next($request);
    }
}
