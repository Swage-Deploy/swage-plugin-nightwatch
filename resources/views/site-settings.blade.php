<div @if ($nightwatch?->daemon?->status->isBusy()) wire:poll.3s @endif>
    <x-panel icon="moon" :heading="__('Nightwatch')" :description="__('Reports to Laravel Nightwatch through an agent Swage runs for the site. The application must require laravel/nightwatch; Swage sets NIGHTWATCH_TOKEN and NIGHTWATCH_INGEST_URI in its .env and keeps the agent running.')">
        @if ($nightwatch)
            <x-slot:actions>
                @if ($nightwatch->daemon === null)
                    <x-status color="red" :label="__('Agent missing')" />
                @elseif ($nightwatch->daemon->status->isBusy())
                    <x-status color="amber" :label="__('Starting agent')" />
                @elseif ($nightwatch->daemon->status === \App\Enums\ResourceStatus::Failed)
                    <x-status color="red" :label="__('Agent failed')" />
                @else
                    <x-status color="green" :label="__('Reporting')" />
                @endif
            </x-slot:actions>
        @endif

        <div wire:init="check">
        @php($disabled = $installed !== true)
        <form wire:submit="save" class="flex flex-col gap-6">
            @if (! $checked)
                <div class="flex items-center gap-2 text-zinc-500">
                    <flux:icon.arrow-path class="size-4 animate-spin" />
                    <flux:text>{{ __('Checking the site for laravel/nightwatch...') }}</flux:text>
                </div>
            @elseif ($installed !== true)
                <flux:callout icon="information-circle" color="amber">
                    <flux:callout.heading>
                        {{ $installed === false ? __('laravel/nightwatch is not installed') : __('Unable to check for laravel/nightwatch') }}
                    </flux:callout.heading>
                    <flux:callout.text>
                        @if ($installed === false)
                            {{ __('Run :command in the application, then deploy the site.', ['command' => 'composer require laravel/nightwatch']) }}
                        @else
                            {{ __('The server could not be reached. Try again in a moment.') }}
                        @endif
                    </flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" wire:click="recheck">{{ __('Check again') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endif

            @if ($nightwatch && $nightwatch->daemon === null)
                <flux:callout icon="exclamation-triangle" color="red">
                    <flux:callout.text>{{ __('The agent\'s daemon was removed, so nothing reaches Nightwatch. Save to start it again.') }}</flux:callout.text>
                </flux:callout>
            @endif

            <flux:input wire:model="token" type="password" viewable :label="__('Environment token')" :description="__('From the environment\'s settings in Nightwatch.')" :placeholder="$nightwatch ? __('Saved. Leave blank to keep it.') : ''" class="font-mono" autocomplete="off" :disabled="$disabled" />
            <flux:input wire:model="request_sample_rate" :label="__('Request sample rate')" :description="__('The share of requests to record, from 0 to 1, such as 0.1 for one in ten. Leave blank to record every request. Exceptions are always recorded.')" placeholder="1.0" class="font-mono" :disabled="$disabled" />

            @if ($nightwatch)
                <flux:text class="text-xs">{{ __('The agent listens on :address.', ['address' => $nightwatch->ingestUri()]) }}</flux:text>
            @endif

            @if ($error)
                <flux:callout icon="x-circle" color="red">
                    <flux:callout.heading>{{ __('The .env could not be updated') }}</flux:callout.heading>
                    <flux:callout.text class="whitespace-pre-wrap font-mono text-xs">{{ $error }}</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" :disabled="$disabled">{{ $nightwatch ? __('Save') : __('Add Nightwatch') }}</flux:button>
                @if ($nightwatch)
                    <flux:button wire:click="remove" variant="ghost">{{ __('Remove') }}</flux:button>
                @endif
            </div>
        </form>
        </div>
    </x-panel>
</div>
