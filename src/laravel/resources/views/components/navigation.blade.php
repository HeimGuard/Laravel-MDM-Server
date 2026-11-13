<div class="layout-nav {{ Cookie::get('layout-nav') }}" id="layout-nav">
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="dropdown">
                <div class="d-flex align-items-center">
                    <div class="app-nav-header flex-grow-1">
                        <div class="app-nav-logo rounded-0">
                            <img alt="" src="{{ asset('/favicon.svg') }}">
                        </div>
                        <div class="app-nav-header-content nav-collapsed-hide">
                            <div class="fw-semibold">{{ config('app.name', 'Laravel') }}</div>
                            <small class="text-body-secondary">{{ __('Device management') }}</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="sidebar-content">
            <ul class="app-nav nav flex-column">
                @foreach ($mainMenuItems as $item)
                    <li class="nav-item {{ ($item->isActive() || $item->isUse()) ? 'is-active' : '' }}">
                        <a class="nav-link" href="{{ route($item->route) }}" @if ($item->isActive() || $item->isUse()) aria-current="page" @endif>
                            <i class="nav-link-ico {{ $item->icon }}"></i>
                            <div class="nav-link-title">{{ __($item->title) }}</div>
                            @if ($item->route === 'notifications' && ($firing = \App\Models\AlertEvent::firingCountFor(auth()->user())) > 0)
                                <span class="badge rounded-pill text-bg-danger ms-auto nav-collapsed-hide" title="{{ trans_choice(':count alert firing|:count alerts firing', $firing, ['count' => $firing]) }}">{{ $firing }}</span>
                            @elseif ($item->route === 'security' && ($severe = \App\Models\SecurityFinding::severeCount()) > 0)
                                <span class="badge rounded-pill text-bg-danger ms-auto nav-collapsed-hide" title="{{ trans_choice(':count severe security finding|:count severe security findings', $severe, ['count' => $severe]) }}">{{ $severe }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
                {{-- The system pages in the same list (system admins only, see GenerateMenus). --}}
                @foreach ($systemMenuItems as $item)
                    <li class="nav-item {{ ($item->isActive() || $item->isUse()) ? 'is-active' : '' }}">
                        <a class="nav-link" href="{{ route($item->route) }}" @if ($item->isActive() || $item->isUse()) aria-current="page" @endif>
                            <i class="nav-link-ico {{ $item->icon }}"></i>
                            <div class="nav-link-title">{{ __($item->title) }}</div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="sidebar-footer">
            <ul class="app-nav nav flex-column mb-1 hide-mobile">
                <li class="nav-item">
                    <div type="button" class="nav-link" onclick="toggleLayoutNav()">
                        <i class="nav-link-ico fas fa-chevron-left nav-collapsed-hide"></i>
                        <i class="nav-link-ico fas fa-chevron-right nav-collapsed-show"></i>
                        <div class="nav-link-title">{{ __('Toggle menu') }}</div>
                    </div>
                </li>
            </ul>

            <div class="dropup">
                <a class="app-nav-user" data-bs-toggle="dropdown">
                    <x-avatar class="app-nav-profile" :user="auth()->user()"/>

                    <div class="app-nav-header-content nav-collapsed-hide">
                        <div class="fw-semibold">{{ Auth::user()->name }}</div>
                        <small class="text-body-secondary">{{ Auth::user()->email }}</small>
                    </div>
                    <div class="ms-auto nav-collapsed-hide">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-three-dots-vertical" viewBox="0 0 16 16">
                            <path d="M9.5 13a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0" />
                        </svg>
                    </div>
                </a>

                <div class="dropdown-menu">
                    <div class="dropdown-header d-flex align-items-center">
                        <div class="fw-semibold me-auto">{{ Auth::user()->name }}</div>
                        <small class="text-body-tertiary">v1.23</small>
                    </div>

                    <a class="dropdown-item" href="{{ route('profile.index') }}">
                        <i class="dropdown-ico  fas fa-user"></i>
                        {{ __('Profile') }}
                    </a>

                    <label class="dropdown-item">
                        <i class="dropdown-ico fas fa-moon"></i>
                        <div class="me-auto">{{ __('Dark Mode') }}</div>
                        <div class="form-switch lh-1">
                            <input {{ Cookie::get('theme') == 'dark' ? 'checked' : '' }} class="form-check-input me-0" id="datk-theme" onchange="toggleDatkTheme()" type="checkbox">
                        </div>
                    </label>

                    <a class="dropdown-item" href="{{ route('profile.api') }}">
                        <i class="dropdown-ico  fas fa-server"></i>
                        {{ __('Api Tokens') }}
                    </a>

                    <hr class="dropdown-divider">

                    <a class="dropdown-item" href="{{ route('logout') }}" onclick="event.preventDefault();document.getElementById('logout-form').submit();">
                        <i class="dropdown-ico  fas fa-sign-out-alt text-danger"></i>
                        {{ __('Logout') }}
                    </a>

                    <form action="{{ route('logout') }}" class="d-none" id="logout-form" method="POST">
                        @csrf
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
