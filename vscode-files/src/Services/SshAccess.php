<?php

namespace VsCodeFiles\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Connection details for the node gateway's SSH server. Logins work like
 * Wings SFTP: "<panel user>.<server short id>" with the panel password or a
 * saved SSH key, plus the ssh.connect permission.
 */
class SshAccess
{
    /**
     * Like Pelican's SFTP details, prefer the node's SFTP alias: the FQDN is often
     * behind an HTTP-only proxy such as Cloudflare, which can't carry SSH.
     */
    public static function host(Server $server): string
    {
        return config('vscode-files.ssh.nodes.' . $server->node_id . '.host')
            ?? config('vscode-files.ssh.host')
            ?: ($server->node->daemon_sftp_alias ?: $server->node->fqdn);
    }

    public static function port(Server $server): int
    {
        return (int) (config('vscode-files.ssh.nodes.' . $server->node_id . '.port')
            ?? config('vscode-files.ssh.port', 2222));
    }

    public static function username(User $user, Server $server): string
    {
        return $user->username . '.' . $server->uuid_short;
    }

    public static function remotePath(Server $server): string
    {
        return '/home/container-' . substr($server->uuid, 0, 8);
    }

    public static function alias(Server $server): string
    {
        $name = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($server->name)), '-');

        return 'pelican-' . ($name !== '' ? $name : $server->uuid_short);
    }

    public static function command(User $user, Server $server): string
    {
        $port = self::port($server);

        return 'ssh ' . ($port !== 22 ? "-p $port " : '') . self::username($user, $server) . '@' . self::host($server);
    }

    public static function sshConfig(User $user, Server $server): string
    {
        return implode("\n", [
            'Host ' . self::alias($server),
            '    HostName ' . self::host($server),
            '    Port ' . self::port($server),
            '    User ' . self::username($user, $server),
        ]);
    }

    /**
     * Opens the server folder in desktop VS Code via Remote-SSH. The authority is
     * hex-encoded JSON so a custom port works without an ~/.ssh/config entry.
     */
    public static function vscodeUri(User $user, Server $server): string
    {
        $authority = bin2hex(json_encode([
            'hostName' => self::host($server),
            'user' => self::username($user, $server),
            'port' => self::port($server),
        ], JSON_UNESCAPED_SLASHES));

        return 'vscode://vscode-remote/ssh-remote+' . $authority . self::remotePath($server);
    }

    /** SHA256 host key fingerprint from the gateway, cached. */
    public static function fingerprint(Server $server): ?string
    {
        return cache()->remember('vscode-files.ssh-fingerprint.' . $server->node_id, now()->addMinutes(10), function () use ($server) {
            try {
                return Http::timeout(5)->get(GatewayTokenService::gatewayUrl($server) . '/ssh-info')->json('fingerprint') ?: null;
            } catch (Throwable) {
                return null;
            }
        });
    }
}
