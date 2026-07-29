<?php

namespace Tests\Unit;

use App\Services\CustomerTrackingChannelAlias;
use LogicException;
use Tests\TestCase;

class CustomerTrackingChannelAliasTest extends TestCase
{
    public function test_alias_is_deterministic_keyed_safe_and_rotates_with_the_token(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $service = app(CustomerTrackingChannelAlias::class);
        $token = str_repeat('A', 80);
        $alias = $service->forToken($token);

        $this->assertSame($alias, $service->forToken($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $alias);
        $this->assertNotSame($alias, $service->forToken(str_repeat('B', 80)));
        $this->assertNotSame($alias, $service->tokenFingerprint($token));
        $this->assertStringNotContainsString($token, $alias);
    }

    public function test_alias_derivation_rejects_missing_token_or_insecure_application_key(): void
    {
        config()->set('app.key', 'short-key');

        $this->expectException(LogicException::class);
        app(CustomerTrackingChannelAlias::class)->forToken(str_repeat('A', 80));
    }

    public function test_alias_derivation_rejects_an_empty_tracking_token(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        $this->expectException(LogicException::class);
        app(CustomerTrackingChannelAlias::class)->forToken('');
    }
}
