<?php

namespace VsCodeFiles\Filament\Pages;

use App\Facades\Activity;
use App\Filament\Server\Resources\Files\FileResource;
use App\Models\Server;
use App\Traits\Filament\CanCustomizeHeaderActions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Locked;
use VsCodeFiles\Filament\FileManagerModeActions;
use VsCodeFiles\Models\FileManagerPreference;
use VsCodeFiles\Services\GatewayTokenService;

class VsCodeFiles extends Page
{
    use CanCustomizeHeaderActions;

    protected static string $resource = FileResource::class;

    protected string $view = 'vscode-files::vscode-files';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Locked]
    public string $launchUrl = '';

    /** @param array<string, mixed> $parameters */
    public static function canAccess(array $parameters = []): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && !$server->isInConflictState()
            && GatewayTokenService::canUse(user(), $server);
    }

    public function mount(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        abort_unless(GatewayTokenService::canUse(user(), $server), 403);

        $this->launchUrl = GatewayTokenService::launchUrl(user(), $server);

        Activity::event('server:file.vscode')->log();

        if (request()->boolean('launch')) {
            redirect()->away($this->launchUrl);
        }
    }

    public function getTitle(): string
    {
        return trans('server/file.title');
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getDefaultHeaderActions(): array
    {
        return [
            FileManagerModeActions::openLocal(),
            ...FileManagerModeActions::make(FileManagerPreference::MODE_VSCODE),
            FileManagerModeActions::newTab(),
        ];
    }
}
