<?php

namespace VsCodeFiles\Filament\Widgets;

use App\Facades\Activity;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Livewire\Attributes\Renderless;
use VsCodeFiles\Services\GatewayTokenService;

class SshConsole extends Widget
{
    protected string $view = 'vscode-files::ssh-console';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server && GatewayTokenService::canUseSsh(user(), $server);
    }

    /**
     * Called from the terminal each time it (re)connects; tokens are single-use.
     */
    #[Renderless]
    public function socketUrl(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        abort_unless(GatewayTokenService::canUseSsh(user(), $server), 403);

        Activity::event('server:ssh.connect')->log();

        return GatewayTokenService::sshSocketUrl(user(), $server);
    }
}
