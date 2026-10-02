<div class="gm-modal">
    @if ($error)
        <pre class="gm-output gm-error">{{ $error }}</pre>
    @elseif (empty($commits))
        <p class="gm-help">{{ trans('git-manager::strings.no_commits') }}</p>
    @else
        <ol class="gm-commits">
            @foreach ($commits as $commit)
                @php($time = \Illuminate\Support\Carbon::createFromTimestamp($commit['time']))
                <li class="gm-commit">
                    <code class="gm-sha" title="{{ $commit['hash'] }}">{{ $commit['short'] }}</code>
                    <div class="gm-commit-main">
                        <div class="gm-commit-subject" title="{{ $commit['subject'] }}">{{ $commit['subject'] }}</div>
                        <div class="gm-meta-line">{{ $commit['author'] }} · <span title="{{ $time->toDayDateTimeString() }}">{{ $time->diffForHumans() }}</span></div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
