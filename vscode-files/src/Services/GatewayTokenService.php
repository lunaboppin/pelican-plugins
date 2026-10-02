<?php

namespace VsCodeFiles\Services;

use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Throwable;
use VsCodeFiles\Permissions;

class GatewayTokenService
{
    public const AUDIENCE = 'vscode-gateway';

    public static function canUse(?User $user, Server $server): bool
    {
        return $user?->can(Permissions::VSCODE_OPEN, $server) ?? false;
    }

    public static function canWrite(User $user, Server $server): bool
    {
        return $user->can(Permissions::VSCODE_WRITE, $server);
    }

    public static function canUseSsh(?User $user, Server $server): bool
    {
        return config('vscode-files.ssh.enabled', true)
            && ($user?->can(Permissions::SSH_CONNECT, $server) ?? false);
    }

    /**
     * Build a single-use websocket URL for an SSH console session.
     */
    public static function sshSocketUrl(User $user, Server $server): string
    {
        // Mount every server on this node that the user owns or is a subuser of
        // with SSH access. Root admins only get the servers they are directly on.
        $servers = $user->directAccessibleServers()
            ->where('servers.node_id', $server->node_id)
            ->get()
            ->filter(fn (Server $s) => $user->can(Permissions::SSH_CONNECT, $s))
            ->map(fn (Server $s) => ['uuid' => $s->uuid, 'name' => $s->name])
            ->values()
            ->all();

        $status = cache()->get("servers.$server->uuid.status");

        $token = self::builder($server, 'ssh-gateway')
            ->withClaim('server_uuid', $server->uuid)
            ->withClaim('user_uuid', $user->uuid)
            ->withClaim('username', $user->username)
            ->withClaim('servers', $servers)
            ->withClaim('info', [
                'name' => $server->name,
                'status' => $status?->value ?? ($server->status?->value ?? 'offline'),
                'node' => $server->node->fqdn,
                'address' => $server->allocation?->address ?? '',
                'disk' => (int) $server->disk,
            ]);

        $config = self::config($server);
        $url = preg_replace('#^http#', 'ws', self::gatewayUrl($server));

        return $url . '/ssh/' . $server->uuid . '?token=' . $token->getToken($config->signer(), $config->signingKey())->toString();
    }

    /**
     * Tell the node gateway to end a user's VS Code and SSH sessions for a server,
     * e.g. after their permissions were reduced. They can reopen with what's left.
     */
    public static function revoke(Server $server, string $userUuid): void
    {
        $config = self::config($server);
        $token = self::builder($server, 'revoke-gateway')
            ->withClaim('server_uuid', $server->uuid)
            ->withClaim('user_uuid', $userUuid)
            ->getToken($config->signer(), $config->signingKey());

        try {
            Http::timeout(10)
                ->post(self::gatewayUrl($server) . '/revoke?token=' . $token->toString())
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('Could not revoke VS Code/SSH sessions', [
                'server' => $server->uuid,
                'user' => $userUuid,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private static function config(Server $server): Configuration
    {
        return Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($server->node->daemon_token));
    }

    private static function builder(Server $server, string $audience): Builder
    {
        $now = CarbonImmutable::now();

        return self::config($server)->builder(ChainedFormatter::withUnixTimestampDates())
            ->issuedBy(config('app.url'))
            ->permittedFor($audience)
            ->identifiedBy(Str::uuid()->toString())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now->subSeconds(30))
            ->expiresAt($now->addSeconds((int) config('vscode-files.token_ttl', 300)));
    }

    public static function gatewayUrl(Server $server): string
    {
        $url = config('vscode-files.nodes.' . $server->node_id) ?? config('vscode-files.gateway_url');

        return rtrim($url, '/');
    }

    /**
     * Build a single-use URL that starts an editor session for this server.
     */
    public static function launchUrl(User $user, Server $server): string
    {
        $config = self::config($server);

        $token = self::builder($server, self::AUDIENCE)
            ->withClaim('server_uuid', $server->uuid)
            ->withClaim('user_uuid', $user->uuid)
            ->withClaim('mode', self::canWrite($user, $server) ? 'rw' : 'ro')
            ->getToken($config->signer(), $config->signingKey());

        return self::gatewayUrl($server) . '/s/' . $server->uuid . '/?token=' . $token->toString();
    }
}
