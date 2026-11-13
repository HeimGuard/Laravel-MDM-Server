<div class="layout-nav-mobile">
    <ul class="app-nav nav">
        @foreach (\SteelAnts\LaravelBoilerplate\Facades\Menu::get('main-menu')?->items() ?? [] as $item)
            <li class="nav-item nav-item-mobile {{ ($item->isActive() || $item->isUse()) ? 'is-active' : '' }}">
                <a class="nav-link position-relative" href="{{ route($item->route) }}" @if ($item->isActive() || $item->isUse()) aria-current="page" @endif>
                    <i class="nav-link-ico {{ $item->icon }}"></i>
                    @if ($item->route === 'notifications' && ($firing = \App\Models\AlertEvent::firingCountFor(auth()->user())) > 0)
                        <span class="position-absolute badge rounded-pill text-bg-danger" style="top: .25rem; left: 55%">{{ $firing }}</span>
                    @elseif ($item->route === 'security' && ($severe = \App\Models\SecurityFinding::severeCount()) > 0)
                        <span class="position-absolute badge rounded-pill text-bg-danger" style="top: .25rem; left: 55%">{{ $severe }}</span>
                    @endif
                    <span class="nav-link-content">{{ __($item->title) }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</div>
