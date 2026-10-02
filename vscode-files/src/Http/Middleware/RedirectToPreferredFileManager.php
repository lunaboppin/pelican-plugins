<?php

namespace VsCodeFiles\Http\Middleware;

use App\Models\Server;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VsCodeFiles\Filament\Pages\VsCodeFiles;
use VsCodeFiles\Models\FileManagerPreference;
use VsCodeFiles\Services\GatewayTokenService;

/**
 * Sends users who picked VS Code mode straight to the editor when they open the file manager.
 */
class RedirectToPreferredFileManager
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('GET') || $request->routeIs('filament.server.resources.files.index') === false) {
            return $next($request);
        }

        $server = Filament::getTenant();
        if (!$server instanceof Server || !GatewayTokenService::canUse(user(), $server)) {
            return $next($request);
        }

        if (FileManagerPreference::modeFor(user()) !== FileManagerPreference::MODE_VSCODE) {
            return $next($request);
        }

        return redirect(VsCodeFiles::getUrl());
    }
}
