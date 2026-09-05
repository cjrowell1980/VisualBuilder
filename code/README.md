# Source code

- `native/` contains the active .NET 10 and WinUI 3 VisualBuilder application.
- `legacy-laravel/` contains the previous Laravel and NativePHP application retained for migration and parity testing.

New VisualBuilder product functionality should normally be implemented in `native/`. Laravel remains the primary generated target, not the framework hosting the new Windows application.
