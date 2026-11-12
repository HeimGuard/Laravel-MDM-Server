{{-- Where something stands, as a small coloured text with an icon: Queued, Installing 40 %, Done,
     Connected. tone: muted (grey), primary, success, warning, danger; spinner: a spinner instead of
     the icon (running). compact: on phones only the icon, so pass the text also as the title. --}}
@props(['icon' => null, 'tone' => 'muted', 'spinner' => false, 'compact' => false])
@php
    $gap = $compact ? '' : ' me-1';
    $mark = match (true) {
        (bool) $spinner => '<span aria-hidden="true" class="spinner-border spinner-border-sm'.$gap.'"></span>',
        (bool) $icon => '<i class="'.e($icon).$gap.'"></i>',
        default => '',
    };
    $label = trim((string) $slot);
    $text = $compact ? '<span class="d-none d-sm-inline ms-1">'.$label.'</span>' : $label;
@endphp
<span {{ $attributes->class(['small text-nowrap', $tone === 'muted' ? 'text-body-secondary' : 'text-'.$tone]) }}>{!! $mark.$text !!}</span>
