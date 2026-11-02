<x-layout-app>
    <div class="container">
        <x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="['' => __('Changelog')]" />
        <div class="page-header">
            <h1 class="hide-mobile">{{ __('Changelog') }}</h1>
        </div>

        {!! $content !!}
    </div>
</x-layout-app>
