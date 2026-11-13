{{-- Polls faster while an action is on its way, so its progress stays current. --}}
<div>
    @if ($alerts)
        {{-- Each smart alert on its own under the device card; the less severe ones behind "Show all". --}}
        <div class="vstack gap-2 mt-3" x-data="{ all: false }">
            @foreach ($alerts as $alert)
                @if ($loop->index < 4)
                    @include('partials.device.alert', ['alert' => $alert, 'device' => $selectedDevice, 'asAlert' => true])
                @else
                    <div x-show="all" x-cloak style="display: none">@include('partials.device.alert', ['alert' => $alert, 'device' => $selectedDevice, 'asAlert' => true])</div>
                @endif
            @endforeach
            @if (count($alerts) > 4)
                <button class="btn btn-sm text-body-secondary align-self-center" type="button" x-bind:aria-expanded="all" x-on:click="all = !all">
                    <span x-show="!all">{{ __('Show all (:count)', ['count' => count($alerts)]) }} <i class="fas fa-chevron-down ms-1"></i></span>
                    <span x-show="all" x-cloak style="display: none">{{ __('Show less') }} <i class="fas fa-chevron-up ms-1"></i></span>
                </button>
            @endif
        </div>
    @endif
</div>
