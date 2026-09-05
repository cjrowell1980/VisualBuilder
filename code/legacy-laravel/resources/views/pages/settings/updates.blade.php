<?php

use App\Services\Updates\ApplicationUpdateStatus;
use Livewire\Attributes\Title;
use Livewire\Component;
use Native\Desktop\Facades\AutoUpdater;

new #[Title('Application updates')] class extends Component
{
    /** @var array{status: string, message: string, version: string|null, percent: float|null, updated_at: string|null} */
    public array $updateStatus = [];

    public function mount(ApplicationUpdateStatus $status): void
    {
        $this->refreshUpdateStatus($status);
    }

    public function checkForUpdates(ApplicationUpdateStatus $status): void
    {
        if (! config('nativephp.updater.enabled')) {
            $status->set('disabled', 'Automatic updates are disabled for this build.');
            $this->refreshUpdateStatus($status);

            return;
        }

        $status->set('checking', 'Checking the release feed for a newer version...');
        AutoUpdater::checkForUpdates();
        $this->refreshUpdateStatus($status);
    }

    public function installUpdate(): void
    {
        if (($this->updateStatus['status'] ?? null) !== 'downloaded') {
            $this->addError('update', 'An update must finish downloading before it can be installed.');

            return;
        }

        AutoUpdater::quitAndInstall();
    }

    public function refreshUpdateStatus(ApplicationUpdateStatus $status): void
    {
        $this->updateStatus = $status->get();
    }
}; ?>

<section class="w-full" wire:poll.2s="refreshUpdateStatus">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Updates')" :subheading="__('Keep the VisualBuilder desktop application current')">
        <div class="space-y-5">
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading>VisualBuilder {{ config('nativephp.version') }}</flux:heading>
                        <flux:text class="mt-1">Provider: {{ config('nativephp.updater.default') }}</flux:text>
                    </div>
                    <flux:badge :color="config('nativephp.updater.enabled') ? 'green' : 'amber'">
                        {{ config('nativephp.updater.enabled') ? 'Enabled' : 'Internal build' }}
                    </flux:badge>
                </div>
            </div>

            <flux:callout
                :variant="in_array($updateStatus['status'] ?? 'idle', ['error', 'disabled'], true) ? 'danger' : (($updateStatus['status'] ?? null) === 'downloaded' ? 'success' : null)"
                :heading="str($updateStatus['status'] ?? 'idle')->headline()"
            >
                <flux:callout.text>{{ $updateStatus['message'] ?? 'No update status is available.' }}</flux:callout.text>
            </flux:callout>

            @if(($updateStatus['percent'] ?? null) !== null)
                <div>
                    <div class="mb-2 flex justify-between text-sm"><span>Download</span><span>{{ number_format($updateStatus['percent'], 1) }}%</span></div>
                    <div class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full bg-indigo-500" style="width: {{ min(100, max(0, $updateStatus['percent'])) }}%"></div></div>
                </div>
            @endif

            @error('update')<flux:callout variant="danger" heading="Update unavailable"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>@enderror

            <div class="flex flex-wrap gap-2">
                <flux:button wire:click="checkForUpdates" icon="arrow-path" :disabled="!config('nativephp.updater.enabled') || ($updateStatus['status'] ?? null) === 'checking'">Check for updates</flux:button>
                <flux:button wire:click="installUpdate" wire:confirm="Quit VisualBuilder and install the downloaded update now?" variant="primary" icon="arrow-down-tray" :disabled="($updateStatus['status'] ?? null) !== 'downloaded'">Install and restart</flux:button>
            </div>

            @if(!config('nativephp.updater.enabled'))
                <flux:text class="text-sm">This unsigned internal build does not contact an update feed. Automatic updates will be enabled in signed release builds using a public release feed.</flux:text>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
