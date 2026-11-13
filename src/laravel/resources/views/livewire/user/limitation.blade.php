<div>
    <h2 class="h6 fw-semibold mt-3 mb-2">{{ __('Limitation') }}</h2>

    <x-form::form wire:submit.prevent="store">
        <x-form::select group-class="mb-3" :options="$this->limits" wire:model="limitation" id="limitation" label="{{ __('Items per page') }}"/>
        <x-form::button class="mb-3 btn-primary" type="submit">{{ __('Save') }}</x-form::button>
    </x-form::form>
</div>
