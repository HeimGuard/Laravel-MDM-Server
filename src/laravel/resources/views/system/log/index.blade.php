<x-dynamic-component :component="$layout">
    <div class="container">
        <x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="[route('system.setting.index') => __('System'), '' => __('Logs')]" />
        <div class="page-header">
            <h1 class="hide-mobile">{{ __('Logs')}}</h1>
            <form method="POST" action="{{ route('system.log.clear') }}" onsubmit="return confirm('{{ __('Do you really want to clear all logs?') }}')">
                @csrf
                <button type="submit" class="btn btn-danger">{{ __('Clear logs') }}</button>
            </form>
        </div>

        <div class="row g-3 mb-4">
            @foreach ($todayStats as $stat => $value)
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body ">
                            <div class="h5 fw-medium">{{ $stat }}</div>
                            <div class="h1 mb-0 {{$stat == "ERROR" ? "text-danger" : "" }}{{$stat == "WARNING" ? "text-warning" : "" }}">{{ $value }}</div>
                            <small class="text-body-tertiary">{{ __('today') }}</small>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Name')}}</th>
                        <th scope="col">{{ __('Size')}}</th>
                        <th scope="col" class="text-end">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <th scope="row">
                                <a href="{{ route('system.log.detail', ['file' => $item['fileName']]) }}#end">{{ $item['fileName'] }}</a>
                            </th>
                            <td>
                                {{ $item['humanReadableSize'] }}
                            </td>
                            <td class="text-end">
                                <a href='{{ route('system.log.download', ['file' => $item['fileName']]) }}' class="btn btn-primary">
                                    <i class="fa fa-download"></i>
                                </a>
                                <form method="POST" action="{{ route('system.log.delete', ['file' => $item['fileName']]) }}" class="d-inline" onsubmit="return confirm('{{ __('Are you sure?') }}')">
                                    @csrf
                                    <button type="submit" class="btn btn-danger"><i class="fa fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-dynamic-component>
