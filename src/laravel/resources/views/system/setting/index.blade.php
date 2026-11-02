<x-dynamic-component :component="$layout">
    <div class="container">
        <x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="[route('system.setting.index') => __('System'), '' => __('boilerplate::setting.title')]" />
        <div class="page-header">
            <h1 class="hide-mobile">{{ __('boilerplate::setting.title') }}</h1>
        </div>

        @livewire('boilerplate.setting.form', ['key' => "main.general"])
    </div>
</x-dynamic-component>
