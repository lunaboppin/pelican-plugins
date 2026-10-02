<div class="gm-modal" x-data="{ copied: false }">
    @if ($key)
        <p class="gm-help">{{ trans('git-manager::strings.deploy_key_help') }}</p>
        <div class="gm-code-row">
            <pre x-ref="key" class="gm-code">{{ $key }}</pre>
            <button type="button" class="gm-copy"
                x-on:click="navigator.clipboard.writeText($refs.key.textContent.trim()); copied = true; setTimeout(() => copied = false, 1500)">
                <x-filament::icon icon="tabler-copy" class="gm-icon-xs" />
                <span x-text="copied ? @js(trans('git-manager::strings.copied')) : @js(trans('git-manager::strings.copy'))">{{ trans('git-manager::strings.copy') }}</span>
            </button>
        </div>
    @else
        <p class="gm-error">{{ trans('git-manager::strings.deploy_key_failed') }}</p>
    @endif
</div>
