<form wire:submit="save">
    <div class="row g-3">
        <div class="col-12 small text-body-secondary">{{ __('TP-Link Easy Smart switches have no SNMP: the agent of their network reads the ports from their web page (agents :version or newer). It is offered for the devices whose MAC address is one of TP-Link. Enter the user name and the password of the switch.', ['version' => \App\Services\SwitchService::VERSION]) }}</div>
        <div class="col-6">
            <label class="form-label" for="tplink-settings-user">{{ __('User name') }}</label>
            <input autocomplete="off" class="form-control @error('user') is-invalid @enderror" id="tplink-settings-user" type="text" wire:model="user">
        </div>
        <div class="col-6">
            <label class="form-label" for="tplink-settings-password">{{ __('Password') }}</label>
            <input autocomplete="new-password" class="form-control" id="tplink-settings-password" placeholder="{{ $hasPassword ? __('Leave empty to keep it') : '' }}" type="password" wire:model="password">
        </div>
        @error('user') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
        <div class="col-12 small text-body-secondary">{{ __('The password is stored encrypted and sent to the agent encrypted with its own key, so only that agent can read it. Use a password made for this switch.') }} {{ __('Leave the user name empty to stop reading this switch.') }}</div>
        @if ($replaces)
            <div class="col-12 small text-warning">{{ __('The device is read over SNMP now: this replaces it.') }}</div>
        @endif
    </div>
    <div class="d-flex justify-content-end gap-2 border-top pt-3 mt-4">
        <button class="btn btn-light" type="button" wire:click="$dispatch('closeModal')">{{ __('Cancel') }}</button>
        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
    </div>
</form>
