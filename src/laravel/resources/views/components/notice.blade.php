{{-- A short message inside a page (an error of an action, a hint, a warning about the data): a small
     Bootstrap alert with an icon on the left and the text beside it, wrapped on its own line.
     type: danger, warning, info or success; icon: the default of the type, or another one
     (e.g. "fab fa-docker"), false for none. role="alert" for errors, otherwise role="status". --}}
@props(['type' => 'info', 'icon' => null])
@php
    $icon ??= match ($type) {
        'danger', 'warning' => 'fas fa-exclamation-triangle',
        'success' => 'fas fa-check',
        default => 'fas fa-info-circle',
    };
@endphp
<div {{ $attributes->class(['alert alert-'.$type, 'd-flex align-items-start gap-2 py-2 small'])->merge(['role' => $type === 'danger' ? 'alert' : 'status']) }}>
    @if ($icon)
        <i class="{{ $icon }} mt-1"></i>
    @endif
    <div class="min-w-0">{{ $slot }}</div>
</div>
