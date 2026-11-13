<form wire:submit="save">
    <div class="form-check form-switch mb-3">
        <input class="form-check-input" id="schedule-enabled" type="checkbox" wire:model.live="enabled">
        <label class="form-check-label" for="schedule-enabled">{{ __('Run on a schedule') }}</label>
    </div>

    <fieldset @disabled(! $enabled)>
        <div class="row g-2 align-items-end mb-1">
            <div class="col-12 col-md-7">
                <label class="form-label" for="schedule-cron">{{ __('Cron expression') }}</label>
                <input class="form-control font-monospace @error('schedule') is-invalid @enderror" id="schedule-cron" placeholder="0 3 * * *" type="text" wire:model.live.debounce.300ms="schedule">
                @error('schedule') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-12 col-md-5">
                <select class="form-select" aria-label="{{ __('Presets') }}" x-on:change="if ($event.target.value) { $wire.set('schedule', $event.target.value) } $event.target.value = ''">
                    <option value="">{{ __('Presets…') }}</option>
                    @foreach (\App\Models\Script::SCHEDULE_PRESETS as $expression => $label)
                        <option value="{{ $expression }}">{{ __($label) }} ({{ $expression }})</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-text mb-3">
            {{ __('minute hour day-of-month month day-of-week, in the time zone :zone.', ['zone' => config('mdm.timezone')]) }}
            @if ($valid && $nextRuns)
                <div><i class="far fa-clock me-1"></i>{{ __('Next runs') }}: {{ collect($nextRuns)->map(fn ($run) => $run->format('D j. n. H:i'))->implode(' · ') }}</div>
            @elseif (! $valid)
                <div class="text-danger">{{ __('Not a valid cron expression (minute hour day month weekday).') }}</div>
            @endif
        </div>

        <label class="form-label">{{ __('Devices') }}</label>
        @include('partials.target-picker', ['agentsOnly' => true])
        @error('target') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
        <div class="small text-body-secondary mt-2">
            {{ trans_choice(':count device matches now|:count devices match now', $targeted, ['count' => $targeted]) }},
            {{ trans_choice(':count can run it|:count can run it', $runnable, ['count' => $runnable]) }}
            ({{ __('platform, signing agent, scripts enabled') }}).
        </div>
    </fieldset>

    <div class="d-flex justify-content-end mt-4">
        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
    </div>
</form>
