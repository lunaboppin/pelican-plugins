<?php

namespace DiskAnalyzer\Filament\Pages;

use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use BackedEnum;
use DiskAnalyzer\Permissions;
use DiskAnalyzer\Services\DiskTokenService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Renderless;

class DiskAnalyzer extends Page
{
    use BlockAccessInConflict;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::ChartPie;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'disk-analyzer';

    protected string $view = 'disk-analyzer::disk-analyzer';

    protected Width|string|null $maxContentWidth = Width::Full;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && !$server->isInConflictState()
            && (user()?->can(Permissions::VIEW, $server) ?? false);
    }

    public function canRescan(): bool
    {
        return user()?->can(Permissions::RESCAN, Filament::getTenant()) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return trans('disk-analyzer::strings.title');
    }

    public function getTitle(): string
    {
        return trans('disk-analyzer::strings.title');
    }

    public function getSubheading(): ?string
    {
        return trans('disk-analyzer::strings.subheading');
    }

    /**
     * Called by the page each time it loads or rescans; tokens are single-use.
     */
    #[Renderless]
    public function reportUrl(bool $refresh = false): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        abort_unless(static::canAccess(), 403);

        // Without rescan permission the cached report (up to 5 minutes old) is served.
        $refresh = $refresh && $this->canRescan();

        if ($refresh) {
            Activity::event('server:disk.scan')->log();
        }

        return DiskTokenService::reportUrl($server, $refresh);
    }
}
