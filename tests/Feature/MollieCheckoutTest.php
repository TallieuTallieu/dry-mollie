<?php

declare(strict_types=1);

/*
 * What pay() tells Mollie's checkout beyond the money: the order it is for,
 * the language, the payment methods and who pays. None of it may cost the
 * payment — a value Mollie would refuse is left out, not sent.
 */

use dry\http\Response;

afterEach(function (): void {
    // dry's default; the locale tests change it.
    Response::$language = 'nl';
});

/**
 * An order placed by someone with a whole Belgian billing address.
 *
 * @return Tests\Support\InMemoryOrder
 */
function orderWithBillingDetails(): Tests\Support\InMemoryOrder
{
    $order = orderAwaitingPayment();
    $order->first_name = 'An';
    $order->last_name = 'Peeters';
    $order->company = 'Peeters BV';
    $order->email = 'an@peeters.example';
    $order->billing_street = 'Kerkstraat';
    $order->billing_number = '12';
    $order->billing_box = '3';
    $order->billing_postal_code = '9000';
    $order->billing_city = 'Gent';
    $order->billing_country = 'be';

    return $order;
}

it('tags the payment with its order', function (): void {
    expect(createdPaymentPayload()['metadata'] ?? null)->toBe([
        'order_id' => 7,
        'reference' => '7-K4M7QX9RTB',
    ]);
});

it('leaves the language to the browser by default', function (): void {
    expect(createdPaymentPayload())->not->toHaveKey('locale');
});

it('sends a configured locale', function (): void {
    expect(
        createdPaymentPayload(['locale' => 'fr_BE'])['locale'] ?? null
    )->toBe('fr_BE');
});

it('picks the locale for the page language from a map', function (): void {
    Response::$language = 'fr';

    $payload = createdPaymentPayload([
        'locale' => ['nl' => 'nl_BE', 'fr' => 'fr_BE'],
    ]);

    expect($payload['locale'] ?? null)->toBe('fr_BE');
});

it('sends no locale for a page language the map lacks', function (): void {
    Response::$language = 'en';

    $payload = createdPaymentPayload([
        'locale' => ['nl' => 'nl_BE', 'fr' => 'fr_BE'],
    ]);

    expect($payload)->not->toHaveKey('locale');
});

it('never sends a locale Mollie would refuse', function (): void {
    // Mollie wants nl_BE; nl or nl-BE would refuse the whole payment.
    expect(createdPaymentPayload(['locale' => 'nl']))->not->toHaveKey('locale');
    expect(createdPaymentPayload(['locale' => 'nl-BE']))->not->toHaveKey(
        'locale'
    );
});

it('offers every method by default', function (): void {
    expect(createdPaymentPayload())->not->toHaveKey('method');
});

it('narrows the methods to a configured list', function (): void {
    $payload = createdPaymentPayload([
        'methods' => ['bancontact', 'creditcard'],
    ]);

    expect($payload['method'] ?? null)->toBe(['bancontact', 'creditcard']);
});

it(
    'sends a single method on its own, skipping the selection',
    function (): void {
        expect(
            createdPaymentPayload(['methods' => 'bancontact'])['method'] ?? null
        )->toBe('bancontact');
        expect(
            createdPaymentPayload(['methods' => ['bancontact']])['method'] ??
                null
        )->toBe('bancontact');
    }
);

it('sends who pays from the order', function (): void {
    // Mollie's SDK orders the fields its own way.
    expect(sentBillingAddress(orderWithBillingDetails()))->toEqual([
        'givenName' => 'An',
        'familyName' => 'Peeters',
        'organizationName' => 'Peeters BV',
        'email' => 'an@peeters.example',
        'streetAndNumber' => 'Kerkstraat 12',
        'streetAdditional' => '3',
        'postalCode' => '9000',
        'city' => 'Gent',
        'country' => 'BE',
    ]);
});

it('sends no postal address unless it is whole', function (): void {
    $order = orderWithBillingDetails();
    $order->billing_country = 'Belgium';

    $address = sentBillingAddress($order);

    expect($address['email'] ?? null)->toBe('an@peeters.example');
    expect($address)->not->toHaveKeys([
        'streetAndNumber',
        'postalCode',
        'city',
        'country',
    ]);
});

it('drops the names and email Mollie would refuse', function (): void {
    $order = orderWithBillingDetails();
    $order->first_name = 'A';
    $order->last_name = '123';
    $order->email = 'not an email';

    $address = sentBillingAddress($order);

    expect($address)->not->toHaveKeys(['givenName', 'familyName', 'email']);
    expect($address['streetAndNumber'] ?? null)->toBe('Kerkstraat 12');
});

it(
    'sends no billing address without an email or a whole postal address',
    function (): void {
        // Mollie refuses an address with neither.
        $order = orderAwaitingPayment();
        $order->first_name = 'An';
        $order->last_name = 'Peeters';

        expect(createdPaymentPayload([], $order))->not->toHaveKey(
            'billingAddress'
        );
    }
);
