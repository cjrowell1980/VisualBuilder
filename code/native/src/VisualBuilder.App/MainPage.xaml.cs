using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Windows.Storage.Pickers;
using VisualBuilder.Application.Projects;
using VisualBuilder.Application.Models;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.App;

public sealed partial class MainPage : Page
{
    private readonly DispatcherTimer _autosaveTimer = new() { Interval = TimeSpan.FromSeconds(30) };
    private ModelDefinition? _selectedModel;

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
        AddModelButton.IsEnabled = true;
        StatusText.Text = "Project ready";
        App.MainWindow.Title = $"{project.Name} — VisualBuilder";
        RefreshModels();
        await RefreshRecentProjectsAsync();
    }

    private async void AddModel_Click(object sender, RoutedEventArgs e)
    {
        var input = await ShowModelDialogAsync("Add model", null);
        if (input is null) return;
        await ApplyModelChangeAsync(() => SelectModel(App.Models.AddModel(input)));
    }

    private async void EditModel_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedModel is null) return;
        var input = await ShowModelDialogAsync("Edit model", _selectedModel);
        if (input is null) return;
        var id = _selectedModel.Id;
        await ApplyModelChangeAsync(() => { App.Models.UpdateModel(id, input); SelectModel(CurrentModels().Single(model => model.Id == id)); });
    }

    private async void DeleteModel_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedModel is null || !await ConfirmAsync("Delete model?", $"Delete {_selectedModel.Name} and all of its fields and relationships?")) return;
        var id = _selectedModel.Id;
        await ApplyModelChangeAsync(() => { App.Models.RemoveModel(id); _selectedModel = null; RefreshModels(); });
    }

    private void ModelsList_ItemClick(object sender, ItemClickEventArgs e)
    {
        if (e.ClickedItem is ModelDefinition model) SelectModel(model);
    }

    private async void AddField_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedModel is null) return;
        var input = await ShowFieldDialogAsync("Add field", null);
        if (input is null) return;
        await ApplyModelChangeAsync(() => App.Models.AddField(_selectedModel.Id, input));
    }

    private async void EditField_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedModel is null || FieldsList.SelectedItem is not FieldDefinition field) return;
        var input = await ShowFieldDialogAsync("Edit field", field);
        if (input is null) return;
        await ApplyModelChangeAsync(() => App.Models.UpdateField(_selectedModel.Id, field.Id, input));
    }

    private async void DeleteField_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedModel is null || FieldsList.SelectedItem is not FieldDefinition field ||
            !await ConfirmAsync("Delete field?", $"Delete the {field.Label} field?")) return;
        await ApplyModelChangeAsync(() => App.Models.RemoveField(_selectedModel.Id, field.Id));
    }

    private void FieldsList_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        EditFieldButton.IsEnabled = FieldsList.SelectedItem is FieldDefinition;
        DeleteFieldButton.IsEnabled = FieldsList.SelectedItem is FieldDefinition;
    }

    private async void AddRelationship_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedModel is null) return;
        var targets = CurrentModels().Where(model => model.Id != _selectedModel.Id).ToArray();
        if (targets.Length == 0)
        {
            await ShowErrorAsync("Another model required", "Add another model before creating a relationship.");
            return;
        }

        var name = new TextBox { Header = "Relationship name", PlaceholderText = "customer" };
        var type = Combo("Relationship type", ModelDesigner.SupportedRelationshipTypes);
        var target = new ComboBox { Header = "Target model", ItemsSource = targets, DisplayMemberPath = "Name", SelectedIndex = 0, HorizontalAlignment = HorizontalAlignment.Stretch };
        var foreignKey = new TextBox { Header = "Foreign key (optional)", PlaceholderText = "customer_id" };
        var pivotTable = new TextBox { Header = "Pivot table (belongs-to-many)", PlaceholderText = "customer_order" };
        var panel = Panel(name, type, target, foreignKey, pivotTable);
        if (await DialogAsync("Add relationship", panel, "Add") != ContentDialogResult.Primary) return;
        if (target.SelectedItem is not ModelDefinition targetModel) return;
        await ApplyModelChangeAsync(() => App.Models.AddRelationship(_selectedModel.Id,
            new(name.Text, type.SelectedItem!.ToString()!, targetModel.Id, foreignKey.Text, pivotTable.Text)));
    }

    private async void DeleteRelationship_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedModel is null || RelationshipsList.SelectedItem is not RelationshipListItem item ||
            !await ConfirmAsync("Delete relationship?", $"Delete the {item.Relationship.Name} relationship?")) return;
        await ApplyModelChangeAsync(() => App.Models.RemoveRelationship(_selectedModel.Id, item.Relationship.Id));
    }

    private void RelationshipsList_SelectionChanged(object sender, SelectionChangedEventArgs e) =>
        DeleteRelationshipButton.IsEnabled = RelationshipsList.SelectedItem is RelationshipListItem;

    private async Task ApplyModelChangeAsync(Action change)
    {
        try
        {
            change();
            if (_selectedModel is not null) SelectModel(CurrentModels().Single(model => model.Id == _selectedModel.Id));
            else RefreshModels();
            StatusText.Text = "Unsaved changes — autosave pending";
        }
        catch (ModelDesignException exception)
        {
            await ShowErrorAsync("Model change rejected", exception.Message);
        }
    }

    private void RefreshModels()
    {
        var models = CurrentModels();
        ModelsList.ItemsSource = null;
        ModelsList.ItemsSource = models;
        NoModelInfo.IsOpen = models.Count == 0;
        if (models.Count == 0)
        {
            ModelEditor.Visibility = Visibility.Collapsed;
            AddRelationshipButton.IsEnabled = false;
            ModelOptionsText.Text = "Select a model";
        }
    }

    private void SelectModel(ModelDefinition model)
    {
        _selectedModel = model;
        ModelsList.SelectedItem = model;
        ModelEditor.Visibility = Visibility.Visible;
        NoModelInfo.IsOpen = false;
        ModelNameText.Text = model.Name;
        ModelTableText.Text = model.TableName;
        ModelOptionsText.Text = $"Timestamps: {(model.Timestamps ? "on" : "off")}  •  Soft deletes: {(model.SoftDeletes ? "on" : "off")}";
        FieldsList.ItemsSource = null;
        FieldsList.ItemsSource = model.Fields;
        RelationshipsList.ItemsSource = null;
        RelationshipsList.ItemsSource = model.Relationships.Select(relationship => new RelationshipListItem(relationship,
            $"{relationship.Type} → {CurrentModels().FirstOrDefault(target => target.Id == relationship.TargetModelId)?.Name ?? "Missing model"}")).ToArray();
        AddRelationshipButton.IsEnabled = true;
        EditFieldButton.IsEnabled = false;
        DeleteFieldButton.IsEnabled = false;
        DeleteRelationshipButton.IsEnabled = false;
    }

    private IReadOnlyList<ModelDefinition> CurrentModels() => App.Workspace.Current?.Iterations[^1].Models ?? [];

    private async Task<ModelInput?> ShowModelDialogAsync(string title, ModelDefinition? model)
    {
        var name = new TextBox { Header = "Model name (PascalCase)", Text = model?.Name ?? "", PlaceholderText = "CustomerOrder" };
        var table = new TextBox { Header = "Database table (snake_case)", Text = model?.TableName ?? "", PlaceholderText = "customer_orders" };
        var timestamps = new CheckBox { Content = "Add created_at and updated_at", IsChecked = model?.Timestamps ?? true };
        var softDeletes = new CheckBox { Content = "Add soft deletes", IsChecked = model?.SoftDeletes ?? false };
        if (await DialogAsync(title, Panel(name, table, timestamps, softDeletes), "Save") != ContentDialogResult.Primary) return null;
        var tableName = string.IsNullOrWhiteSpace(table.Text) ? ModelDesigner.SuggestedTableName(name.Text) : table.Text;
        return new(name.Text, tableName, timestamps.IsChecked == true, softDeletes.IsChecked == true);
    }

    private async Task<FieldInput?> ShowFieldDialogAsync(string title, FieldDefinition? field)
    {
        var name = new TextBox { Header = "Field name (snake_case)", Text = field?.Name ?? "", PlaceholderText = "company_name" };
        var label = new TextBox { Header = "Label", Text = field?.Label ?? "", PlaceholderText = "Company name" };
        var type = Combo("Field type", ModelDesigner.SupportedFieldTypes);
        type.SelectedItem = field?.Type ?? "string";
        var defaultValue = new TextBox { Header = "Default value (optional)", Text = field?.DefaultValue?.ToString() ?? "" };
        var rules = new TextBox { Header = "Laravel validation rules (comma separated)", Text = field is null ? "" : string.Join(", ", field.ValidationRules), PlaceholderText = "required, max:120" };
        var nullable = new CheckBox { Content = "Nullable", IsChecked = field?.Nullable ?? false };
        var indexed = new CheckBox { Content = "Indexed", IsChecked = field?.Indexed ?? false };
        var unique = new CheckBox { Content = "Unique (automatically indexed)", IsChecked = field?.Unique ?? false };
        if (await DialogAsync(title, Panel(name, label, type, defaultValue, rules, nullable, indexed, unique), "Save") != ContentDialogResult.Primary) return null;
        return new(name.Text, label.Text, type.SelectedItem!.ToString()!, nullable.IsChecked == true,
            indexed.IsChecked == true, unique.IsChecked == true, defaultValue.Text,
            rules.Text.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries));
    }

    private async Task<ContentDialogResult> DialogAsync(string title, object content, string primaryText) => await new ContentDialog
    {
        XamlRoot = XamlRoot, Title = title, Content = content, PrimaryButtonText = primaryText,
        CloseButtonText = "Cancel", DefaultButton = ContentDialogButton.Primary
    }.ShowAsync();

    private async Task<bool> ConfirmAsync(string title, string message) => await new ContentDialog
    {
        XamlRoot = XamlRoot, Title = title, Content = message, PrimaryButtonText = "Delete",
        CloseButtonText = "Cancel", DefaultButton = ContentDialogButton.Close
    }.ShowAsync() == ContentDialogResult.Primary;

    private static StackPanel Panel(params UIElement[] children)
    {
        var panel = new StackPanel { Spacing = 12 };
        foreach (var child in children) panel.Children.Add(child);
        return panel;
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

    private sealed record RelationshipListItem(RelationshipDefinition Relationship, string Summary);
}
