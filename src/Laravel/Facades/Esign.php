<?php

namespace Joba\Esign\Laravel\Facades;

/**
 * @method static \Joba\Esign\Resources\File file()
 * @method static \Joba\Esign\Resources\Template template()
 * @method static \Joba\Esign\Resources\SignFlow signFlow()
 * @method static \Joba\Esign\Resources\Identity identity()
 * @method static \Joba\Esign\Resources\Seal seal()
 * @method static \Joba\Esign\Resources\Organization organization()
 * @method static \Joba\Esign\Resources\Order order()
 * @method static \Joba\Esign\CallbackVerifier callback()
 * @method static array request(string $method, string $path, ?array $body = null, array $query = [])
 *
 * @see \Joba\Esign\Client
 */
class Esign extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return 'esign';
    }
}
