<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\Updates\ApplicationUpdateStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Native\Desktop\Events\AutoUpdater\DownloadProgress;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Facades\AutoUpdater;
use Tests\TestCase;

class ApplicationUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_page_requires_authentication(): void
    {
        $this->get(route('updates.edit'))->assertRedirect(route('login'));
    }

    public function test_internal_build_explains_that_updates_are_disabled(): void
    {
        config()->set('nativephp.updater.enabled', false);
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.updates')
            ->call('checkForUpdates')
            ->assertSee('Automatic updates are disabled for this build.');
    }

    public function test_enabled_build_can_check_for_updates(): void
    {
        config()->set('nativephp.updater.enabled', true);
        AutoUpdater::shouldReceive('checkForUpdates')->once();
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.updates')
            ->call('checkForUpdates')
            ->assertSet('updateStatus.status', 'checking');
    }

    public function test_native_update_events_are_reflected_in_the_status_store(): void
    {
        event(new DownloadProgress(1000, 500, 500, 50, 100));
        $this->assertSame(50.0, app(ApplicationUpdateStatus::class)->get()['percent']);

        event(new UpdateDownloaded('update.exe', '1.2.0', [], now()->toIso8601String()));
        $this->assertSame('downloaded', app(ApplicationUpdateStatus::class)->get()['status']);
        $this->assertSame('1.2.0', app(ApplicationUpdateStatus::class)->get()['version']);
    }

    public function test_downloaded_update_can_be_installed(): void
    {
        app(ApplicationUpdateStatus::class)->set('downloaded', 'Ready', '1.2.0', 100);
        AutoUpdater::shouldReceive('quitAndInstall')->once();
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.updates')->call('installUpdate')->assertHasNoErrors();
    }
}
