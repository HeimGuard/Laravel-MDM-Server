{{-- A syslog message no parser reads: a parser that reads it (system admins). --}}
@can('is-system-admin')
    <button class="btn btn-sm btn-outline-secondary text-nowrap" type="button" title="{{ __('Add a parser that reads this message') }}"
        x-on:click="Livewire.dispatch('openModal', ['security-scan.rule-form', @js(__('Add parser')), {kind: 'parser', {!! ($event->cache_key ?? null) ? "eventKey: '".$event->cache_key."'" : 'eventId: '.(int) $event->id !!}}, 'xl'])">
        <i class="fas fa-plus me-1"></i>{{ __('Add parser') }}
    </button>
@endcan
