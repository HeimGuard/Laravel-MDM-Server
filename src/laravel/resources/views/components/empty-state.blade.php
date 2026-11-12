{{-- The row of an empty list or table: "No devices.", "Nothing needs attention.". Grey, centred.
     In a list-group by default; with colspan it is the row of a table (<tr><td colspan>).
     icon: an optional icon before the text, e.g. "fas fa-shield-alt text-success". --}}
@props(['icon' => null, 'colspan' => null])
@if ($colspan)
    <tr>
        <td {{ $attributes->class(['text-body-secondary text-center py-4']) }} colspan="{{ $colspan }}">@if ($icon)<i class="{{ $icon }} me-2"></i>@endif{{ $slot }}</td>
    </tr>
@else
    <div {{ $attributes->class(['list-group-item text-body-secondary text-center py-4']) }}>@if ($icon)<i class="{{ $icon }} me-2"></i>@endif{{ $slot }}</div>
@endif
