<?php

namespace DiskAnalyzer\Providers;

use App\Enums\TablerIcon;
use App\Models\Subuser;
use Illuminate\Support\ServiceProvider;

class DiskAnalyzerProvider extends ServiceProvider
{
    public function register(): void
    {
        Subuser::registerCustomPermissions('disk', ['view', 'rescan'], 'disk-analyzer::permissions', TablerIcon::ChartPie);
    }
}
