<?php

namespace Joba\Esign\Resources;

class Order extends Resource
{
    public function placeOrderUrl(array $body): array
    {
        return $this->client->request('POST', '/v3/orders/org-place-order-url', $body);
    }

    public function remainingQuantity(array $query): array
    {
        return $this->client->request('GET', '/v3/orders/remaining-quantity', null, $query);
    }

    public function search(array $query): array
    {
        return $this->client->request('GET', '/v3/orders/order-list', null, $query);
    }

    public function manageUrl(array $body): array
    {
        return $this->client->request('POST', '/v3/orders/org-order-manage-url', $body);
    }
}
