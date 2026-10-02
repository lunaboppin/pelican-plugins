<?php

namespace DiskAnalyzer\Services;

use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class DiskTokenService
{
    public const AUDIENCE = 'disk-gateway';

    /**
     * Build a single-use URL that returns the disk usage report for a server.
     */
    public static function reportUrl(Server $server, bool $refresh = false): string
    {
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($server->node->daemon_token));
        $now = CarbonImmutable::now();

        $token = $config->builder(ChainedFormatter::withUnixTimestampDates())
            ->issuedBy(config('app.url'))
            ->permittedFor(self::AUDIENCE)
            ->identifiedBy(Str::uuid()->toString())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now->subSeconds(30))
            ->expiresAt($now->addSeconds((int) config('disk-analyzer.token_ttl', 120)))
            ->withClaim('server_uuid', $server->uuid)
            ->withClaim('user_uuid', user()?->uuid)
            ->getToken($config->signer(), $config->signingKey());

        $gateway = rtrim(config('disk-analyzer.nodes.' . $server->node_id) ?? config('disk-analyzer.gateway_url'), '/');

        return $gateway . '/disk/' . $server->uuid . '?token=' . $token->toString() . ($refresh ? '&refresh=1' : '');
    }
}
