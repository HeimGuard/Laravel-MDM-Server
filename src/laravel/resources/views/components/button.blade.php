{{-- A small button with an icon and a label (an <a> with href). variant: light (default), primary,
     outline-primary, ...; icon: "fas fa-download"; tone: the text colour of the icon (e.g. "primary").
     Rule for the tint: the icon of a light button is not tinted (tone null), except the primary action
     of a row (Update, Install) which is "primary".
     loading: the wire:target of the action; while it runs a spinner takes the place of the icon.
     busy: the same spinner for an action that is already running (the state comes from the server).
     Rule for the label on phones: only the buttons in a row of a table or a list (where the space is
     tight: the Update button of an update row, Scan in a network row) pass compact, the label is then
     shown from sm up and the button needs a title (it is also the aria-label). Toolbar and page header
     buttons always keep their label, and buttons side by side behave the same.
     wire:click, wire:loading.attr, title, disabled, x-on:click and classes are passed through. --}}
@props(['icon' => null, 'variant' => 'light', 'tone' => null, 'compact' => false, 'loading' => null, 'busy' => false, 'href' => null])
@php
    $label = trim((string) $slot);
    $gap = $label === '' ? '' : ($compact ? ' me-sm-1' : ' me-1');
    $iconClass = $icon ? e($icon).$gap.($tone ? ' text-'.e($tone) : '') : null;
    $spinner = 'spinner-border spinner-border-sm'.$gap;
    $attrs = $attributes->class(['btn btn-sm btn-'.$variant]);
    $attrs = $href ? $attrs->merge(['href' => $href]) : $attrs->merge(['type' => 'button']);
    if ($compact && ! $attrs->has('aria-label') && $attrs->has('title')) {
        // The title is escaped already, so it is not escaped again.
        $attrs = $attrs->merge(['aria-label' => $attrs->get('title')], false);
    }
    $content = match (true) {
        (bool) $busy => '<span aria-hidden="true" class="'.$spinner.'"></span>',
        $icon && $loading => '<i class="'.$iconClass.'" wire:loading.remove wire:target="'.e($loading).'"></i><span aria-hidden="true" class="'.$spinner.'" wire:loading wire:target="'.e($loading).'"></span>',
        (bool) $icon => '<i class="'.$iconClass.'"></i>',
        default => '',
    };
    $content .= $compact && $label !== '' ? '<span class="d-none d-sm-inline">'.$label.'</span>' : $label;
    $tag = $href ? 'a' : 'button';
@endphp
<{{ $tag }} {{ $attrs }}>{!! $content !!}</{{ $tag }}>
