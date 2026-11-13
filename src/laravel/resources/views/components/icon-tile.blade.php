{{-- A square icon in a coloured tile (.icon-tile in app.scss), before the title of a row, a card or
     an alert. tone: a Bootstrap colour (primary, success, warning, danger, ...) or null for the
     neutral grey tile; size="lg" for the big one in the header of the device. Other attributes
     (title, classes) go to the tile. --}}
@props(['icon', 'tone' => null, 'size' => null])
<span {{ $attributes->class([
    'icon-tile',
    'icon-tile-'.$size => $size,
    'bg-'.$tone.'-subtle text-'.$tone.'-emphasis' => $tone,
]) }}><i class="{{ $icon }}"></i></span>
