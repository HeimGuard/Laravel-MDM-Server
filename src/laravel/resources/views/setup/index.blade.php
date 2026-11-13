<x-layout-auth>
    <h2 class="h4 mb-1">{{ __('Welcome') }}</h2>
    <p class="text-body-secondary mb-4">{{ __('Create the first account. It is the system admin and can add other users later.') }}</p>

    <x-form::form method="POST" action="{{ route('setup.store') }}">
        {{-- Picking a language shows the page in it right away (SetLocale keeps ?lang= until signing in). --}}
        <div class="mb-3">
            <label class="form-label" for="locale">{{ __('Language') }}:</label>
            <select class="form-select @error('locale') is-invalid @enderror" name="locale" id="locale" onchange="window.location.search = '?lang=' + this.value">
                @foreach ($locales as $code => $name)
                    <option value="{{ $code }}" @selected(old('locale', app()->getLocale()) === $code)>{{ $name }}</option>
                @endforeach
            </select>
            @error('locale')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <x-form::input class="mb-3" type="text" name="name" id="name" label="{{ __('Name') }}:" required autofocus />
        <x-form::input class="mb-3" type="email" name="email" id="email" label="{{ __('Email') }}:" required />
        <x-form::input class="mb-3" type="password" name="password" id="password" label="{{ __('Password') }}:" required autocomplete="new-password" />
        <x-form::input class="mb-3" type="password" name="password_confirmation" id="password_confirmation" label="{{ __('Confirm Password') }}:" required autocomplete="new-password" />

        <x-form::button class="btn-primary" type="submit">{{ __('Create account') }}</x-form::button>
    </x-form::form>
</x-layout-auth>
