{{-- Override of the boilerplate breadcrumb: a quoted href, the last item (the current page) as text. --}}
<nav {{ $attributes }} aria-label="breadcrumb">
    <ol class="breadcrumb">
        @foreach ($items as $item)
            @if ($loop->last)
                <li aria-current="page" class="breadcrumb-item active text-truncate">{{ $item['name'] }}</li>
            @else
                <li class="breadcrumb-item"><a href="{{ $item['link'] }}">{{ $item['name'] }}</a></li>
            @endif
        @endforeach
    </ol>
</nav>
