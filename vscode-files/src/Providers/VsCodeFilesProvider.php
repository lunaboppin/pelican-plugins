<?php

namespace VsCodeFiles\Providers;

use App\Enums\ConsoleWidgetPosition;
use App\Enums\HeaderActionPosition;
use App\Enums\TablerIcon;
use App\Filament\Server\Pages\Console;
use App\Filament\Server\Resources\Files\FileResource;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Enums\Size;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use VsCodeFiles\Filament\FileManagerModeActions;
use VsCodeFiles\Filament\Pages\VsCodeFiles;
use VsCodeFiles\Filament\Widgets\SshConsole;
use VsCodeFiles\Models\FileManagerPreference;
use VsCodeFiles\Permissions;
use VsCodeFiles\Services\GatewayTokenService;

class VsCodeFilesProvider extends ServiceProvider
{
    public function register(): void
    {
        Subuser::registerCustomPermissions('vscode', ['open', 'write'], 'vscode-files::permissions', TablerIcon::BrandVscode);
        Subuser::registerCustomPermissions('ssh', ['connect'], 'vscode-files::permissions', TablerIcon::Terminal2);

        // Registered pages are merged ahead of the defaults, so this route wins over the index catch-all.
        FileResource::registerCustomPages([
            'vscode' => VsCodeFiles::route('/vscode'),
        ]);

        ListFiles::registerCustomHeaderActions(
            HeaderActionPosition::Before,
            FileManagerModeActions::openLocal(),
            ...FileManagerModeActions::make(FileManagerPreference::MODE_LIST),
        );

        self::registerSshConsole();
    }

    public function boot(): void
    {
        // End open sessions when someone loses access, so a revoked permission
        // takes effect immediately rather than when their session expires.
        $sessionPermissions = [Permissions::VSCODE_OPEN, Permissions::VSCODE_WRITE, Permissions::SSH_CONNECT];

        Subuser::updated(function (Subuser $subuser) use ($sessionPermissions) {
            $before = array_intersect((array) $subuser->getOriginal('permissions'), $sessionPermissions);
            if (array_diff($before, (array) $subuser->permissions)) {
                self::revokeLater($subuser->server, $subuser->user?->uuid);
            }
        });

        Subuser::deleted(function (Subuser $subuser) use ($sessionPermissions) {
            if (array_intersect((array) $subuser->permissions, $sessionPermissions)) {
                self::revokeLater($subuser->server, $subuser->user?->uuid);
            }
        });

        Server::updated(function (Server $server) {
            if ($server->wasChanged('owner_id')) {
                self::revokeLater($server, User::find($server->getOriginal('owner_id'))?->uuid);
            }
        });
    }

    private static function revokeLater(?Server $server, ?string $userUuid): void
    {
        if ($server && $userUuid) {
            defer(fn () => GatewayTokenService::revoke($server, $userUuid));
        }
    }

    private static function registerSshConsole(): void
    {
        Console::registerCustomWidgets(ConsoleWidgetPosition::BelowConsole, [SshConsole::class]);

        Console::registerCustomHeaderActions(
            HeaderActionPosition::After,
            Action::make('ssh_console')
                ->button()
                ->label(fn () => new HtmlString(
                    '<span class="pssh-label-open">' . e(trans('vscode-files::strings.ssh_console')) . '</span>'
                    . '<span class="pssh-label-close">' . e(trans('vscode-files::strings.close_ssh_console')) . '</span>'
                ))
                ->icon(TablerIcon::Terminal2)
                ->color('gray')
                ->size(Size::ExtraLarge)
                ->visible(fn () => SshConsole::canView())
                ->alpineClickHandler("\$dispatch('toggle-ssh-console')"),
        );
    }

}
