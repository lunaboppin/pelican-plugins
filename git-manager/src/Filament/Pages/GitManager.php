<?php

namespace GitManager\Filament\Pages;

use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use GitManager\Models\GitCredential;
use GitManager\Models\GitWebhook;
use GitManager\Permissions;
use GitManager\Services\GitGateway;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Throwable;

class GitManager extends Page
{
    use BlockAccessInConflict;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::BrandGit;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'git';

    protected string $view = 'git-manager::git-manager';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** @var array<int, array<string, mixed>> */
    public array $repos = [];

    public bool $scanned = false;

    public ?string $scanError = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server
            && !$server->isInConflictState()
            && (user()?->can(Permissions::VIEW, $server) ?? false);
    }

    public static function getNavigationLabel(): string
    {
        return trans('git-manager::strings.title');
    }

    public function getTitle(): string
    {
        return trans('git-manager::strings.title');
    }

    public function getSubheading(): ?string
    {
        return trans('git-manager::strings.subheading');
    }

    public function userCan(string $permission): bool
    {
        return user()?->can($permission, $this->server()) ?? false;
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    public function scan(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $result = GitGateway::call($this->server(), 'scan');
            $this->repos = $result['repos'] ?? [];
            $this->scanError = $result['ok'] ? null : (trim((string) ($result['output'] ?? '')) ?: trans('git-manager::strings.scan_failed'));
        } catch (Throwable $exception) {
            report($exception);
            $this->scanError = trans('git-manager::strings.scan_failed');
        }

        $this->scanned = true;
    }

    /** @return Collection<int, GitCredential> */
    #[Computed]
    public function credentials(): Collection
    {
        return GitCredential::query()->where('server_id', $this->server()->id)->orderBy('host')->get();
    }

    /** @return Collection<int, GitWebhook> */
    #[Computed]
    public function webhooks(): Collection
    {
        return GitWebhook::query()->where('server_id', $this->server()->id)->orderBy('path')->get();
    }

    /** Only act on repositories the last scan found. */
    private function knownRepo(array $arguments): string
    {
        $path = (string) ($arguments['path'] ?? '');
        abort_unless(collect($this->repos)->contains('path', $path), 404);

        return $path;
    }

    private static function button(Action $action): Action
    {
        return $action->button()->size(Size::Small)->iconSize(IconSize::Small)->color('gray');
    }

    private static function outputHtml(string $output): HtmlString
    {
        $output = trim(Str::of($output)->substr(-1500)->toString());

        return new HtmlString('<pre style="margin:0.25rem 0 0;max-height:15rem;overflow:auto;white-space:pre-wrap;font-family:ui-monospace,monospace;font-size:0.75rem">' . e($output) . '</pre>');
    }

    public function pullAction(): Action
    {
        return self::button(Action::make('pull'))
            ->label(trans('git-manager::strings.pull'))
            ->icon(TablerIcon::ArrowDown)
            ->visible(fn () => $this->userCan(Permissions::PULL))
            ->action(function (array $arguments) {
                abort_unless($this->userCan(Permissions::PULL), 403);
                $path = $this->knownRepo($arguments);

                try {
                    $result = GitGateway::call($this->server(), 'pull', ['path' => $path]);
                } catch (Throwable $exception) {
                    $result = ['ok' => false, 'output' => $exception->getMessage()];
                }

                Activity::event('server:git.pull')->property('path', $path)->property('ok', $result['ok'])->log();

                Notification::make()
                    ->title(trans($result['ok'] ? 'git-manager::strings.pull_success' : 'git-manager::strings.pull_failed', ['path' => $path]))
                    ->body(self::outputHtml((string) ($result['output'] ?? '')))
                    ->status($result['ok'] ? 'success' : 'danger')
                    ->persistent(!$result['ok'])
                    ->send();

                $this->scan();
            });
    }

    public function historyAction(): Action
    {
        return self::button(Action::make('history'))
            ->label(trans('git-manager::strings.history'))
            ->icon(TablerIcon::History)
            ->modalHeading(fn (array $arguments) => trans('git-manager::strings.history_heading', ['path' => $arguments['path'] ?? '']))
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(trans('git-manager::strings.close'))
            ->modalContent(function (array $arguments) {
                $path = $this->knownRepo($arguments);

                try {
                    $result = GitGateway::call($this->server(), 'log', ['path' => $path]);
                    $commits = $result['commits'] ?? [];
                    $error = $result['ok'] ? null : ($result['output'] ?? null);
                } catch (Throwable $exception) {
                    $commits = [];
                    $error = $exception->getMessage();
                }

                return view('git-manager::commit-history', ['commits' => $commits, 'error' => $error]);
            });
    }

    public function openFilesAction(): Action
    {
        return self::button(Action::make('openFiles'))
            ->label(trans('git-manager::strings.open_files'))
            ->icon(TablerIcon::FolderOpen)
            ->url(fn (array $arguments) => ListFiles::getUrl(['path' => encode_path(ltrim((string) ($arguments['path'] ?? ''), '/'))]));
    }

    public function cloneAction(): Action
    {
        return Action::make('clone')
            ->button()
            ->size(Size::Small)
            ->iconSize(IconSize::Small)
            ->label(trans('git-manager::strings.clone'))
            ->icon(TablerIcon::Plus)
            ->visible(fn () => $this->userCan(Permissions::PULL))
            ->modalHeading(trans('git-manager::strings.clone'))
            ->modalSubmitActionLabel(trans('git-manager::strings.clone'))
            ->schema([
                TextInput::make('url')
                    ->label(trans('git-manager::strings.clone_url'))
                    ->helperText(trans('git-manager::strings.clone_url_help'))
                    ->placeholder('git@github.com:org/repo.git')
                    ->required()
                    ->maxLength(500)
                    ->regex('/^(https:\/\/[^\s]+|ssh:\/\/[^\s]+|[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._~\/-]+)$/')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, callable $get, callable $set) {
                        if (!$get('path') && $state) {
                            $set('path', '/' . Str::of($state)->afterLast('/')->afterLast(':')->beforeLast('.git')->toString());
                        }
                    }),
                TextInput::make('path')
                    ->label(trans('git-manager::strings.clone_path'))
                    ->helperText(trans('git-manager::strings.clone_path_help'))
                    ->placeholder('/garrysmod/addons/my-addon')
                    ->required()
                    ->maxLength(255)
                    ->notIn(['/', ''])
                    ->regex('/^\/?[^\x00]+$/'),
                TextInput::make('branch')
                    ->label(trans('git-manager::strings.clone_branch'))
                    ->helperText(trans('git-manager::strings.clone_branch_help'))
                    ->maxLength(200)
                    ->regex('/^[A-Za-z0-9._\/][A-Za-z0-9._\/-]*$/'),
            ])
            ->action(function (array $data) {
                abort_unless($this->userCan(Permissions::PULL), 403);
                $path = '/' . ltrim($data['path'], '/');

                try {
                    $result = GitGateway::call($this->server(), 'clone', ['url' => $data['url'], 'path' => $path, 'branch' => (string) ($data['branch'] ?? '')]);
                } catch (Throwable $exception) {
                    $result = ['ok' => false, 'output' => $exception->getMessage()];
                }

                Activity::event('server:git.clone')->property('path', $path)->property('url', $data['url'])->property('ok', $result['ok'])->log();

                Notification::make()
                    ->title($result['ok'] ? trans('git-manager::strings.clone_success', ['path' => $path]) : trans('git-manager::strings.clone_failed'))
                    ->body(self::outputHtml((string) ($result['output'] ?? '')))
                    ->status($result['ok'] ? 'success' : 'danger')
                    ->persistent(!$result['ok'])
                    ->send();

                $this->scan();
            });
    }

    public function deployKeyAction(): Action
    {
        return Action::make('deployKey')
            ->button()
            ->color('gray')
            ->label(trans('git-manager::strings.view_deploy_key'))
            ->icon(TablerIcon::Key)
            ->extraAttributes(['class' => 'w-full'])
            ->visible(fn () => $this->userCan(Permissions::MANAGE))
            ->modalHeading(trans('git-manager::strings.deploy_key'))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(trans('git-manager::strings.close'))
            ->modalContent(function () {
                abort_unless($this->userCan(Permissions::MANAGE), 403);

                try {
                    $key = GitGateway::call($this->server(), 'deploy_key')['public_key'] ?? null;
                } catch (Throwable $exception) {
                    report($exception);
                    $key = null;
                }

                return view('git-manager::deploy-key', ['key' => $key]);
            })
            ->extraModalFooterActions([
                Action::make('regenerateKey')
                    ->button()
                    ->icon(TablerIcon::Refresh)
                    ->label(trans('git-manager::strings.regenerate'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(trans('git-manager::strings.regenerate_confirm'))
                    ->action(function () {
                        abort_unless($this->userCan(Permissions::MANAGE), 403);
                        GitGateway::call($this->server(), 'deploy_key', ['regenerate' => true]);
                        Activity::event('server:git.deploy-key.regenerate')->log();
                        Notification::make()->title(trans('git-manager::strings.regenerated'))->success()->send();
                    }),
            ]);
    }

    public function addCredentialAction(): Action
    {
        return Action::make('addCredential')
            ->button()
            ->color('gray')
            ->label(trans('git-manager::strings.add_credential'))
            ->icon(TablerIcon::Plus)
            ->extraAttributes(['class' => 'w-full'])
            ->visible(fn () => $this->userCan(Permissions::MANAGE))
            ->modalHeading(trans('git-manager::strings.add_credential'))
            ->schema([
                TextInput::make('host')
                    ->label(trans('git-manager::strings.host'))
                    ->helperText(trans('git-manager::strings.host_help'))
                    ->default('github.com')
                    ->required()
                    ->maxLength(255)
                    ->regex('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/'),
                TextInput::make('username')
                    ->label(trans('git-manager::strings.username'))
                    ->required()
                    ->maxLength(255)
                    ->regex('/^[^\t\n\r]+$/'),
                TextInput::make('token')
                    ->label(trans('git-manager::strings.token'))
                    ->helperText(trans('git-manager::strings.token_help'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->maxLength(1000)
                    ->regex('/^[^\t\n\r]+$/'),
            ])
            ->action(function (array $data) {
                abort_unless($this->userCan(Permissions::MANAGE), 403);

                GitCredential::query()->updateOrCreate(
                    ['server_id' => $this->server()->id, 'host' => strtolower($data['host'])],
                    ['username' => $data['username'], 'token' => $data['token']],
                );
                unset($this->credentials);

                Activity::event('server:git.credential.create')->property('host', strtolower($data['host']))->log();
                Notification::make()->title(trans('git-manager::strings.credential_saved'))->success()->send();
            });
    }

    public function deleteCredentialAction(): Action
    {
        return Action::make('deleteCredential')
            ->iconButton()
            ->icon(TablerIcon::Trash)
            ->color('danger')
            ->tooltip(trans('git-manager::strings.delete'))
            ->requiresConfirmation()
            ->visible(fn () => $this->userCan(Permissions::MANAGE))
            ->action(function (array $arguments) {
                abort_unless($this->userCan(Permissions::MANAGE), 403);

                $credential = GitCredential::query()->where('server_id', $this->server()->id)->findOrFail($arguments['id'] ?? 0);
                Activity::event('server:git.credential.delete')->property('host', $credential->host)->log();
                $credential->delete();
                unset($this->credentials);
            });
    }

    public function addWebhookAction(): Action
    {
        return Action::make('addWebhook')
            ->button()
            ->color('gray')
            ->label(trans('git-manager::strings.add_webhook'))
            ->icon(TablerIcon::Webhook)
            ->extraAttributes(['class' => 'w-full'])
            ->visible(fn () => $this->userCan(Permissions::MANAGE))
            ->modalHeading(trans('git-manager::strings.add_webhook'))
            ->schema([
                Select::make('path')
                    ->label(trans('git-manager::strings.webhook_repo'))
                    ->options(fn () => collect($this->repos)->pluck('path', 'path')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('branch')
                    ->label(trans('git-manager::strings.webhook_branch'))
                    ->helperText(trans('git-manager::strings.webhook_branch_help'))
                    ->maxLength(200)
                    ->regex('/^[A-Za-z0-9._\/][A-Za-z0-9._\/-]*$/'),
            ])
            ->action(function (array $data, Action $action) {
                abort_unless($this->userCan(Permissions::MANAGE), 403);
                $this->knownRepo($data);

                $webhook = GitWebhook::query()->create([
                    'server_id' => $this->server()->id,
                    'path' => $data['path'],
                    'branch' => $data['branch'] ?: null,
                ]);
                unset($this->webhooks);

                Activity::event('server:git.webhook.create')->property('path', $webhook->path)->log();
                $this->replaceMountedAction('showWebhook', ['id' => $webhook->id]);
            });
    }

    public function showWebhookAction(): Action
    {
        return Action::make('showWebhook')
            ->iconButton()
            ->icon(TablerIcon::Eye)
            ->color('gray')
            ->tooltip(trans('git-manager::strings.show'))
            ->visible(fn () => $this->userCan(Permissions::MANAGE))
            ->modalHeading(trans('git-manager::strings.webhook_setup'))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(trans('git-manager::strings.close'))
            ->modalContent(function (array $arguments) {
                abort_unless($this->userCan(Permissions::MANAGE), 403);
                $webhook = GitWebhook::query()->where('server_id', $this->server()->id)->findOrFail($arguments['id'] ?? 0);

                return view('git-manager::webhook', ['webhook' => $webhook]);
            });
    }

    public function deleteWebhookAction(): Action
    {
        return Action::make('deleteWebhook')
            ->iconButton()
            ->icon(TablerIcon::Trash)
            ->color('danger')
            ->tooltip(trans('git-manager::strings.delete'))
            ->requiresConfirmation()
            ->visible(fn () => $this->userCan(Permissions::MANAGE))
            ->action(function (array $arguments) {
                abort_unless($this->userCan(Permissions::MANAGE), 403);

                $webhook = GitWebhook::query()->where('server_id', $this->server()->id)->findOrFail($arguments['id'] ?? 0);
                Activity::event('server:git.webhook.delete')->property('path', $webhook->path)->log();
                $webhook->delete();
                unset($this->webhooks);
            });
    }
}
