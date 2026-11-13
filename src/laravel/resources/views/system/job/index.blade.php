<x-dynamic-component :component="$layout">
	<div class="container">
		<x-boilerplate::breadcrumb class="hide-mobile mb-2" :items="[route('system.setting.index') => __('System'), '' => __('Jobs')]" />
		<div class="page-header">
			<h1>{{ __("Jobs") }}</h1>
			<form method="POST" action="{{ route('system.jobs.clear') }}" onsubmit="return confirm('{{ __('Do you really want to clear all jobs?') }}')">
				@csrf
				<button type="submit" class="btn btn-danger">
					<i class="me-1 fas fa-trash"></i>
					<span>{{ __('Clear jobs') }}</span>
				</button>
			</form>
		</div>

		<div class="page-header mb-2">
			<h2 class="h6 fw-semibold mb-0">{{ __('Start job') }}</h2>
		</div>
		<div class="">
			@foreach ($jobs_classes as $job)
				<x-form::button class="btn-primary" group-class="mb-3"
					onclick="Livewire.dispatch('openModal', {livewireComponents: 'job.form', title:  '{{ $job }}', parameters: {job: '{{ $job }}'}})">{{ $job }}</x-form::button>
			@endforeach
		</div>

		<div class="page-header mb-2 mt-4">
			<h2 class="h6 fw-semibold mb-0">{{ __("Waiting") }} <span class="badge text-bg-secondary">{{ $waiting_count }}</span></h2>
			<form method="POST" action="{{ route('system.jobs.stop') }}" onsubmit="return confirm('{{ __('Do you really want to stop all waiting jobs?') }}')">
				@csrf
				<button type="submit" class="btn btn-warning btn-sm">
					<i class="me-1 fas fa-stop"></i>
					<span>{{ __('Stop jobs') }}</span>
				</button>
			</form>
		</div>
        @livewire('job.data-table', [], key('data-table'))

		<div class="page-header mb-2 mt-4">
			<h2 class="h6 fw-semibold mb-0">{{ __("Failed") }} <span class="badge text-bg-secondary">{{ $failed_count }}</span></h2>
			<form method="POST" action="{{ route('system.jobs.rerun') }}" onsubmit="return confirm('{{ __('Do you really want to rerun all failed jobs?') }}')">
				@csrf
				<button type="submit" class="btn btn-success btn-sm">
					<i class="me-1 fas fa-redo"></i>
					<span>{{ __('Rerun jobs') }}</span>
				</button>
			</form>
		</div>
        @livewire('job.data-table', ['failed' => true], key('data-table'))
	</div>
</x-dynamic-component>
