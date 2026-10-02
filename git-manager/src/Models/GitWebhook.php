<?php

namespace GitManager\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A GitHub/GitLab push webhook that pulls one repository.
 *
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property string $path
 * @property ?string $branch
 * @property string $secret
 * @property ?\Carbon\Carbon $last_triggered_at
 * @property ?string $last_status
 * @property ?string $last_output
 * @property Server $server
 */
class GitWebhook extends Model
{
    protected $table = 'git_manager_webhooks';

    protected $fillable = ['server_id', 'path', 'branch', 'secret', 'last_triggered_at', 'last_status', 'last_output'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_triggered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $webhook) {
            $webhook->uuid ??= (string) Str::uuid();
            $webhook->secret ??= Str::random(40);
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function url(): string
    {
        return route('git-manager.webhook', ['uuid' => $this->uuid]);
    }
}
