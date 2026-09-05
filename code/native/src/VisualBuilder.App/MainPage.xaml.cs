using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Windows.Storage.Pickers;
using VisualBuilder.Application.Projects;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.App;

public sealed partial class MainPage : Page
{
    private readonly DispatcherTimer _autosaveTimer = new() { Interval = TimeSpan.FromSeconds(30) };

    public MainPage()
    {
        InitializeComponent();
        Loaded += MainPage_Loaded;
        _autosaveTimer.Tick += AutosaveTimer_Tick;
        _autosaveTimer.Start();
    }

    private async void MainPage_Loaded(object sender, RoutedEventArgs e) => await RefreshRecentProjectsAsync();

    private async void NewProject_Click(object sender, RoutedEventArgs e)
    {
        var name = new TextBox { Header = "Project name", PlaceholderText = "Customer Portal" };
        var description = new TextBox { Header = "Description", AcceptsReturn = true, Height = 72 };
        var applicationType = Combo("Application type", "Web application", "API only", "Web application and API");
        var starterKit = Combo("Starter kit", "Livewire", "Blank Laravel", "API");
        var database = Combo("Database", "PostgreSQL", "MySQL", "SQLite");
        var docker = new CheckBox { Content = "Include Docker configuration", IsChecked = true };
        var fields = new StackPanel { Spacing = 12 };
        fields.Children.Add(name); fields.Children.Add(description); fields.Children.Add(applicationType);
        fields.Children.Add(starterKit); fields.Children.Add(database); fields.Children.Add(docker);

        var dialog = new ContentDialog
        {
            XamlRoot = XamlRoot,
            Title = "Create a VisualBuilder project",
            Content = fields,
            PrimaryButtonText = "Choose location",
            CloseButtonText = "Cancel",
            DefaultButton = ContentDialogButton.Primary
        };

        if (await dialog.ShowAsync() != ContentDialogResult.Primary) return;
        if (string.IsNullOrWhiteSpace(name.Text))
        {
            await ShowErrorAsync("Project name required", "Enter a name before creating the project.");
            return;
        }

        var picker = new FileSavePicker { SuggestedFileName = Slug.Create(name.Text) };
        picker.FileTypeChoices.Add("VisualBuilder project", [".vbproject"]);
        InitializePicker(picker);
        var file = await picker.PickSaveFileAsync();
        if (file is null) return;

        try
        {
            var definition = new NewProjectDefinition(name.Text, (ApplicationType)applicationType.SelectedIndex,
                (StarterKit)starterKit.SelectedIndex, (DatabaseEngine)database.SelectedIndex,
                docker.IsChecked == true, description.Text);
            await App.Workspace.CreateAsync(definition, file.Path);
            await ProjectOpenedAsync();
        }
        catch (Exception exception)
        {
            await ShowErrorAsync("Project could not be created", exception.Message);
        }
    }

    private async void OpenProject_Click(object sender, RoutedEventArgs e)
    {
        var picker = new FileOpenPicker();
        picker.FileTypeFilter.Add(".vbproject");
        InitializePicker(picker);
        var file = await picker.PickSingleFileAsync();
        if (file is not null) await OpenProjectAsync(file.Path);
    }

    private async void RecentProjects_ItemClick(object sender, ItemClickEventArgs e)
    {
        if (e.ClickedItem is RecentProject recent) await OpenProjectAsync(recent.Path);
    }

    private async void SaveProject_Click(object sender, RoutedEventArgs e)
    {
        await SaveCurrentProjectAsync("Saved");
    }

    private async void AutosaveTimer_Tick(object? sender, object e)
    {
        if (App.Workspace.IsDirty) await SaveCurrentProjectAsync("Autosaved");
    }

    private async Task OpenProjectAsync(string path)
    {
        try
        {
            await App.Workspace.OpenAsync(path);
            await ProjectOpenedAsync();
        }
        catch (Exception exception)
        {
            await ShowErrorAsync("Project could not be opened", exception.Message);
        }
    }

    private async Task ProjectOpenedAsync()
    {
        var project = App.Workspace.Current!.Project;
        ProjectNameText.Text = project.Name;
        ProjectSummaryText.Text = $"{Display(project.ApplicationType)} • {Display(project.StarterKit)} • {Display(project.Database)}";
        EmptyState.Visibility = Visibility.Collapsed;
        ProjectState.Visibility = Visibility.Visible;
        SaveButton.IsEnabled = true;
        StatusText.Text = "Project ready";
        App.MainWindow.Title = $"{project.Name} — VisualBuilder";
        await RefreshRecentProjectsAsync();
    }

    private async Task SaveCurrentProjectAsync(string status)
    {
        try
        {
            await App.Workspace.SaveAsync();
            StatusText.Text = $"{status} at {DateTime.Now:t}";
        }
        catch (Exception exception)
        {
            StatusText.Text = "Save failed";
            await ShowErrorAsync("Project could not be saved", exception.Message);
        }
    }

    private async Task RefreshRecentProjectsAsync() => RecentProjectsList.ItemsSource = await App.RecentProjects.LoadAsync();

    private async Task ShowErrorAsync(string title, string message) => await new ContentDialog
    {
        XamlRoot = XamlRoot, Title = title, Content = message, CloseButtonText = "Close"
    }.ShowAsync();

    private static ComboBox Combo(string header, params string[] values)
    {
        var combo = new ComboBox { Header = header, HorizontalAlignment = HorizontalAlignment.Stretch, SelectedIndex = 0 };
        foreach (var value in values) combo.Items.Add(value);
        return combo;
    }

    private static string Display<T>(T value) where T : Enum => value.ToString();
    private static void InitializePicker(object picker) => WinRT.Interop.InitializeWithWindow.Initialize(
        picker, WinRT.Interop.WindowNative.GetWindowHandle(App.MainWindow));
}
