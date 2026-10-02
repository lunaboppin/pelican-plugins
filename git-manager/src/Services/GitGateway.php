<?php

namespace GitManager\Services;

use App\Models\Server;
use Carbon\CarbonImmutable;
use GitManager\Models\GitCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use RuntimeException;

/**
 * Runs git operations on the node through pelican-vscode-gateway. Calls are
 * server-to-server so credentials never reach the browser. There is
 * deliberately no push.
 */
class GitGateway
{
    public const AUDIENCE = 'git-gateway';

    /** @return array{ok: bool, output?: string, repos?: array<int, array<string, mixed>>, commits?: array<int, array<string, mixed>>, public_key?: string} */
    public static function call(Server $server, string $action, array $payload = [], ?string $userUuid = null): array
    {
        $timeout = (int) config('git-manager.timeout', 290);
        @set_time_limit($timeout + 15);

        if (in_array($action, ['pull', 'clone'], true)) {
            $payload['credentials'] = GitCredential::forGateway($server);
        }

        $response = Http::timeout($timeout + 5)
            ->acceptJson()
            ->post(self::url($server, $action, $userUuid), ['action' => $action, ...$payload]);

        $data = $response->json();
        if (!is_array($data)) {
            throw new RuntimeException('The node returned an unexpected response (HTTP ' . $response->status() . ').');
        }
        if ($response->failed() && empty($data['output'])) {
            throw new RuntimeException('The node rejected the request (HTTP ' . $response->status() . ').');
        }

        return $data + ['ok' => false];
    }

    private static function url(Server $server, string $action, ?string $userUuid): string
    {
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($server->node->daemon_token));
        $now = CarbonImmutable::now();

        $token = $config->builder(ChainedFormatter::withUnixTimestampDates())
            ->issuedBy(config('app.url'))
            ->permittedFor(self::AUDIENCE)
            ->identifiedBy(Str::uuid()->toString())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now->subSeconds(30))
            ->expiresAt($now->addSeconds((int) config('git-manager.token_ttl', 60)))
            ->withClaim('server_uuid', $server->uuid)
            ->withClaim('user_uuid', $userUuid ?? user()?->uuid ?? 'webhook')
            ->withClaim('action', $action)
            ->getToken($config->signer(), $config->signingKey());

        $gateway = rtrim(config('git-manager.nodes.' . $server->node_id) ?? config('git-manager.gateway_url'), '/');

        return $gateway . '/git/' . $server->uuid . '?token=' . $token->toString();
    }
}
