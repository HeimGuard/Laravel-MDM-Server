<x-dynamic-component :component="$layout">
    <div class="container">
        <x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="[route('system.setting.index') => __('System'), route('system.log.index') => __('Logs'), '' => $filename]" />
        <div class="page-header">
            <h1 class="hide-mobile">{{ __('File')}} - {{ $filename }}</h1>
            <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="autoload-toggle" role="switch" checked>
                    <label class="form-check-label" for="autoload-toggle">{{ __('Auto-load') }}</label>
                </div>
                <a href='{{ route('system.log.download', ['file' => $filename]) }}' class="btn btn-primary" title="{{ __('Download') }}" aria-label="{{ __('Download') }}">
                    <i class="fa fa-download"></i>
                </a>
                <form method="POST" action="{{ route('system.log.delete', ['file' => $filename]) }}" class="d-inline" onsubmit="return confirm('{{ __('Are you sure?') }}')">
                    @csrf
                    <button type="submit" class="btn btn-danger" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><i class="fa fa-trash"></i></button>
                </form>
            </div>
        </div>

        <pre id="log-output" class="p-2 border" style="white-space: pre-wrap; word-break: break-all;">{{ implode("\n", $lines) }}</pre>
        <div id="end"></div>
    </div>

    @php
        $logTailConfig = [
            'url'    => route('system.log.tail', ['file' => $filename]),
            'offset' => $lineCount,
        ];
    @endphp
    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            initLogTail(@json($logTailConfig));
        });
    </script>
    @endpush
</x-dynamic-component>
