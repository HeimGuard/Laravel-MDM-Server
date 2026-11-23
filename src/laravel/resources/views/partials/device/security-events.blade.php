<div class="table-responsive">
    <table class="table table-sm align-middle mb-0 small">
        <tbody>
            @forelse ($events as $event)
                <tr x-show="!search || @js(mb_strtolower($event->label.' '.$event->user.' '.$event->source.' '.$event->message)).includes(search.toLowerCase())">
                    <td class="text-nowrap text-body-secondary" title="{{ $event->occurred_at }}">{{ $event->occurred_at->diffForHumans() }}</td>
                    <td class="fw-medium text-nowrap">{{ $event->label }}</td>
                    <td class="text-end">{{ $event->count }}×</td>
                    <td class="text-break">{{ $event->user }}</td>
                    <td class="d-none d-md-table-cell text-break text-body-secondary">{{ $event->source }}</td>
                    <td class="d-none d-lg-table-cell text-break text-body-secondary">{{ $event->message }}</td>
                    <td class="text-end">@if ($event->type === 'syslog')@include('partials.device.add-parser', ['event' => $event])@endif</td>
                </tr>
            @empty
                <x-empty-state colspan="7">{{ __('No events.') }}</x-empty-state>
            @endforelse
        </tbody>
    </table>
</div>
<a class="small d-inline-block mt-2" href="{{ route('security', ['tab' => 'events']) }}">{{ __('All events') }}</a>
