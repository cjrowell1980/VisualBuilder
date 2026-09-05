<?php

namespace App\Providers;

use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        Window::open('visual-builder')
            ->title('VisualBuilder')
            ->route('projects.index')
            ->width(1440)
            ->height(900)
            ->minWidth(1024)
            ->minHeight(700)
            ->rememberState()
            ->preventLeaveDomain()
            ->suppressNewWindows();
    }

    /**
     * Return an array of php.ini directives to be set.
     *
     * @return array<string, string>
     */
    public function phpIni(): array
    {
        return ['memory_limit' => '512M'];
    }
}
