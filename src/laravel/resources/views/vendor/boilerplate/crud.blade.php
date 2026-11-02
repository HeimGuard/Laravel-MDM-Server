{{-- Override of the boilerplate CRUD page: the breadcrumb (System › … on system pages) and .container. --}}
<x-dynamic-component :component="$layout">
	<div class="container">
		<x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="request()->routeIs('system.*') ? [route('system.setting.index') => __('System'), '' => $title] : ['' => $title]" />
		<div class="page-header">
			<h1>{{ $title }}</h1>
			@if(!empty($model_back))
			<a class="btn btn-secondary" href="{{ route($model_back) }}">
				<i class="me-2 fas fa-arrow-left"></i><span>{{ __('Back') }}</span>
			</a>
				@endif
				@if(isset($options['headerButtons']))
				{!! $options['headerButtons'] !!}
				@endif
				@if(isset($options['livewireComponents']))
				<button class="btn btn-primary" onclick="Livewire.dispatch('openModal', {{ json_encode($options) }})">
					<i class="me-2 fas fa-plus"></i><span>{{ __('Add') }}</span>
				</button>
				@elseif(isset($full_page_component))
				<a class="btn btn-primary" href="{{ route($full_page_component) }}">
					<i class="me-2 fas fa-plus"></i><span>{{ __('Add') }}</span>
				</a>
			@endif
		</div>
		<x-boilerplate::alerts />
		@livewire($page_component, (isset($data) ? $data : []), key($page_component))
	</div>
</x-dynamic-component>
