<div class="gm-modal">
    <p class="gm-help">{{ trans('git-manager::strings.webhook_help') }}</p>

    @foreach ([trans('git-manager::strings.webhook_url') => $webhook->url(), trans('git-manager::strings.webhook_secret') => $webhook->secret] as $label => $value)
        <div x-data="{ copied: false }">
            <div class="gm-label">{{ $label }}</div>
            <div class="gm-code-row">
                <code x-ref="value" class="gm-code is-single">{{ $value }}</code>
                <button type="button" class="gm-copy"
                    x-on:click="navigator.clipboard.writeText($refs.value.textContent.trim()); copied = true; setTimeout(() => copied = false, 1500)">
                    <x-filament::icon icon="tabler-copy" class="gm-icon-xs" />
                    <span x-text="copied ? @js(trans('git-manager::strings.copied')) : @js(trans('git-manager::strings.copy'))">{{ trans('git-manager::strings.copy') }}</span>
                </button>
            </div>
        </div>
    @endforeach

    <div class="gm-meta-line">
        {{ $webhook->path }}@if ($webhook->branch) · {{ $webhook->branch }}@endif
    </div>
</div>
