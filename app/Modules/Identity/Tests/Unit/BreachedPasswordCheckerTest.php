<?php

declare(strict_types=1);

use App\Modules\Identity\Gateways\Fake\FakeBreachedPasswordChecker;
use App\Modules\Identity\Gateways\Hibp\HibpBreachedPasswordChecker;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

it('sends only the first five characters of the SHA-1 hash, with padding', function (): void {
    $http = new Factory;
    $hash = strtoupper(sha1('correct horse battery staple'));
    $http->fake(['*' => $http->response(substr($hash, 5).":42\r\n0000000000000000000000000000000000A:0\r\n")]);

    $checker = new HibpBreachedPasswordChecker($http);

    expect($checker->isBreached('correct horse battery staple'))->toBeTrue();

    $http->assertSent(static fn (Request $request): bool => $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)
        && $request->hasHeader('Add-Padding', 'true')
        && ! str_contains($request->url().json_encode($request->headers()), substr($hash, 5)));
});

it('ignores padding entries with a zero count', function (): void {
    $http = new Factory;
    $hash = strtoupper(sha1('padded-only-password'));
    $http->fake(['*' => $http->response(substr($hash, 5).":0\r\n")]);

    expect(new HibpBreachedPasswordChecker($http)->isBreached('padded-only-password'))->toBeFalse();
});

it('fails open when the service is down', function (): void {
    $http = new Factory;
    $http->fake(['*' => $http->response('', 503)]);

    expect(new HibpBreachedPasswordChecker($http)->isBreached('anything-at-all'))->toBeFalse();
});

it('flags well-known passwords in the fake', function (): void {
    $fake = new FakeBreachedPasswordChecker;

    expect($fake->isBreached('Password1234'))->toBeTrue()
        ->and($fake->isBreached('a-long-unique-passphrase'))->toBeFalse();
});
