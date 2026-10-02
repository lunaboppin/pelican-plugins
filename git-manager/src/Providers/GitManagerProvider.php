<?php

namespace GitManager\Providers;

use App\Enums\TablerIcon;
use App\Models\Subuser;
use GitManager\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class GitManagerProvider extends ServiceProvider
{
    public function register(): void
    {
        Subuser::registerCustomPermissions('git', ['view', 'pull', 'manage'], 'git-manager::permissions', TablerIcon::BrandGit);
    }

    public function boot(): void
    {
        // Public endpoint for GitHub/GitLab; requests are verified by signature, not session.
        Route::post('git-webhooks/{uuid}', WebhookController::class)
            ->whereUuid('uuid')
            ->middleware('throttle:30,1')
            ->name('git-manager.webhook');
    }
}
