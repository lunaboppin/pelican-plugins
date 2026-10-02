<?php

namespace GitManager\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An HTTPS username + access token used when pulling from one git host.
 *
 * @property int $id
 * @property int $server_id
 * @property string $host
 * @property string $username
 * @property string $token
 */
class GitCredential extends Model
{
    protected $table = 'git_manager_credentials';

    protected $fillable = ['server_id', 'host', 'username', 'token'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function maskedToken(): string
    {
        return '••••' . substr($this->token, -4);
    }

    /** @return array<int, array{host: string, username: string, password: string}> */
    public static function forGateway(Server $server): array
    {
        return static::query()->where('server_id', $server->id)->get()
            ->map(fn (self $c) => ['host' => $c->host, 'username' => $c->username, 'password' => $c->token])
            ->all();
    }
}
