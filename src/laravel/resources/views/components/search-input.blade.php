{{-- The search field above a list or a table: small, at most 20rem wide (it shrinks on phones).
     placeholder defaults to "Search" and is also its aria-label; wire:model / x-model and classes
     (flex-grow-1, mb-3, ...) are passed through. width: another max width, or null for none. --}}
@props(['width' => '20rem'])
@php
    // Attribute values are escaped already (the defaults here by e()), so merge() does not escape them again.
    $placeholder = $attributes->get('placeholder', e(__('Search')));
    $defaults = ['type' => 'search', 'placeholder' => $placeholder, 'aria-label' => $attributes->get('aria-label', $placeholder)];
    if ($width) {
        $defaults['style'] = 'max-width: '.$width.';';
    }
@endphp
<input {{ $attributes->class(['form-control form-control-sm'])->merge($defaults, false) }}>
