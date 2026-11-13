<div class="card card-body h-100 overflow-auto">
    <div class="lh-1 mb-3">
        <h5 class="mb-0">{{ $config['name'] }}</h5>
        <small class="text-body-secondary">{{ $config['description'] }}</small>
    </div>

    @livewire('smart-alerts', ['minSeverity' => $severity, 'limit' => $limit], key('smart-alerts-' . ($widgetId ?? 'widget')))
</div>
