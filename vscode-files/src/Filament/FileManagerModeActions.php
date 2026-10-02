<?php

namespace VsCodeFiles\Filament;

use App\Enums\TablerIcon;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Livewire\Component;
use VsCodeFiles\Filament\Pages\VsCodeFiles;
use VsCodeFiles\Models\FileManagerPreference;
use VsCodeFiles\Services\GatewayTokenService;
use VsCodeFiles\Services\SshAccess;

/**
 * The List Mode / VS Code toggle shown on both file manager pages, built from
 * one place so the two pages look identical. Names use Pelican's "fm_" prefix,
 * which keeps file manager actions as labelled buttons regardless of the user's
 * icon-button preference.
 */
class FileManagerModeActions
{
    /** @return Action[] */
    public static function make(string $current): array
    {
        return [
            self::mode('fm_mode_list', FileManagerPreference::MODE_LIST, $current, TablerIcon::List, 'vscode-files::strings.list_mode'),
            self::mode('fm_mode_vscode', FileManagerPreference::MODE_VSCODE, $current, TablerIcon::BrandVscode, 'vscode-files::strings.vscode'),
        ];
    }

    /** Opens this server in desktop VS Code over the gateway's SSH port. */
    public static function openLocal(): Action
    {
        return Action::make('fm_open_local_vscode')
            ->button()
            ->label(fn () => trans('vscode-files::strings.open_local_vscode'))
            ->icon(TablerIcon::BrandVscode)
            ->color('gray')
            ->outlined()
            ->visible(function () {
                $server = Filament::getTenant();

                return $server instanceof Server && GatewayTokenService::canUseSsh(user(), $server);
            })
            ->url(fn () => SshAccess::vscodeUri(user(), Filament::getTenant()));
    }

    public static function newTab(): Action
    {
        return Action::make('fm_vscode_new_tab')
            ->button()
            ->label(fn () => trans('vscode-files::strings.new_tab'))
            ->icon(TablerIcon::ExternalLink)
            ->color('gray')
            ->outlined()
            ->url(fn () => VsCodeFiles::getUrl(['launch' => 1]), shouldOpenInNewTab: true);
    }

    private static function mode(string $name, string $mode, string $current, TablerIcon $icon, string $label): Action
    {
        $active = $mode === $current;

        return Action::make($name)
            ->button()
            ->label(fn () => trans($label))
            ->icon($icon)
            ->color($active ? 'primary' : 'gray')
            ->outlined(!$active)
            ->visible(fn () => self::canUseVsCode())
            ->extraAttributes($active ? ['aria-pressed' => 'true', 'style' => 'pointer-events: none'] : [])
            ->action(function (Component $livewire) use ($mode, $active) {
                if ($active) {
                    return;
                }

                FileManagerPreference::setModeFor(user(), $mode);

                $url = $mode === FileManagerPreference::MODE_VSCODE ? VsCodeFiles::getUrl() : ListFiles::getUrl();
                $livewire->redirect($url, navigate: true);
            });
    }

    private static function canUseVsCode(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server && GatewayTokenService::canUse(user(), $server);
    }
}
