<?php

namespace VsCodeFiles\Filament\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use VsCodeFiles\Services\GatewayTokenService;
use VsCodeFiles\Services\SshAccess;

class SshDetails extends Page
{
    use BlockAccessInConflict;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Terminal2;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'ssh';

    protected string $view = 'vscode-files::ssh-details';

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && !$server->isInConflictState()
            && GatewayTokenService::canUseSsh(user(), $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('vscode-files::strings.ssh_details');
    }

    public function getTitle(): string
    {
        return trans('vscode-files::strings.ssh_details');
    }

    public function getSubheading(): ?string
    {
        return trans('vscode-files::strings.ssh_details_sub');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $user = user();

        return [
            'host' => SshAccess::host($server),
            'port' => SshAccess::port($server),
            'username' => SshAccess::username($user, $server),
            'command' => SshAccess::command($user, $server),
            'sshConfig' => SshAccess::sshConfig($user, $server),
            'alias' => SshAccess::alias($server),
            'vscodeUri' => SshAccess::vscodeUri($user, $server),
            'remotePath' => SshAccess::remotePath($server),
            'fingerprint' => SshAccess::fingerprint($server),
            // The panel only accepts SSH logins from owners, admins and subusers with SFTP access.
            'missingSftp' => !$user->can(SubuserPermission::FileSftp, $server),
            'profileUrl' => Filament::getPanel('app')->getProfileUrl(),
        ];
    }
}
