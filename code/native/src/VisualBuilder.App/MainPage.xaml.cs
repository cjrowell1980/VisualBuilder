using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Navigation;
using Windows.Storage.Pickers;
using VisualBuilder.Application.Projects;
using VisualBuilder.Application.Models;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.App;

public sealed partial class MainPage : Page
{
    private readonly DispatcherTimer _autosaveTimer = new() { Interval = TimeSpan.FromSeconds(30) };
    private ModelDefinition? _selectedModel;
    private Guid? _requestedModelId;

    public MainPage()
    {
        InitializeComponent();
        Loaded += MainPage_Loaded;
        _autosaveTimer.Tick += AutosaveTimer_Tick;
        _autosaveTimer.Start();
    }

    protected override void OnNavigatedTo(NavigationEventArgs e)
    {
        base.OnNavigatedTo(e);
        _requestedModelId = e.Parameter is Guid id ? id : null;
    }

    private async void MainPage_Loaded(object sender, RoutedEventArgs e)
    {
        if (App.Workspace.Current is not null) await ProjectOpenedAsync();
        else { ResetWorkspaceView(); await RefreshRecentProjectsAsync(); }
    }

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
        if (!await CanReplaceCurrentProjectAsync()) return;

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
        if (file is not null && await CanReplaceCurrentProjectAsync()) await OpenProjectAsync(file.Path);
    }

    private async void RecentProjects_ItemClick(object sender, ItemClickEventArgs e)
    {
        if (e.ClickedItem is RecentProject recent && await CanReplaceCurrentProjectAsync()) await OpenProjectAsync(recent.Path);
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
        ExplorerPane.Visibility = Visibility.Visible;
        PropertiesPane.Visibility = Visibility.Visible;
        SaveButton.IsEnabled = true;
        FileSaveItem.IsEnabled = true;
        FileCloseItem.IsEnabled = true;
        AddModelButton.IsEnabled = true;
        OpenPageDesignerButton.IsEnabled = true;
        StatusText.Text = "Project ready";
        App.MainWindow.Title = $"{project.Name} — VisualBuilder";
        _selectedModel = null;
        RefreshModels();
        var initialModel = _requestedModelId is Guid id
            ? CurrentModels().FirstOrDefault(model => model.Id == id)
            : null;
        initialModel ??= CurrentModels().FirstOrDefault();
        if (initialModel is not null) SelectModel(initialModel);
        _requestedModelId = null;
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
        await ApplyModelChangeAsync(() => { App.Models.RemoveModel(id); _selectedModel = null; });
    }

    private void ModelsList_ItemClick(object sender, ItemClickEventArgs e)
    {
        if (e.ClickedItem is ModelDefinition model) SelectModel(model);
    }

    private void ExplorerPagesList_ItemClick(object sender, ItemClickEventArgs e)
    {
        if (e.ClickedItem is PageDefinition page) Frame.Navigate(typeof(PageDesignerPage), page.Id);
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
        var field = FieldsList.SelectedItem as FieldDefinition;
        EditFieldButton.IsEnabled = field is not null;
        DeleteFieldButton.IsEnabled = field is not null;
        FieldDetailsText.Text = field is null
            ? "Select a field to view validation."
            : $"Field: {field.Name}\nValidation: {(field.ValidationRules.Count == 0 ? "None" : string.Join(", ", field.ValidationRules))}";
    }

    private async void AddRelationship_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedModel is null) return;
        var input = await ShowRelationshipDialogAsync("Add relationship", null);
        if (input is not null) await ApplyModelChangeAsync(() => App.Models.AddRelationship(_selectedModel.Id, input));
    }

    private async void EditRelationship_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedModel is null || RelationshipsList.SelectedItem is not RelationshipListItem item) return;
        var input = await ShowRelationshipDialogAsync("Edit relationship", item.Relationship);
        if (input is not null) await ApplyModelChangeAsync(() => App.Models.UpdateRelationship(_selectedModel.Id, item.Relationship.Id, input));
    }

    private async void DeleteRelationship_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedModel is null || RelationshipsList.SelectedItem is not RelationshipListItem item) return;
        var targetName = CurrentModels().FirstOrDefault(model => model.Id == item.Relationship.TargetModelId)?.Name ?? "target model";
        if (!await ConfirmAsync("Delete relationship?",
            $"Delete {_selectedModel.Name}.{item.Relationship.Name} → {targetName}? This removes the reference and allows {targetName} to be deleted if it has no other incoming references.")) return;
        await ApplyModelChangeAsync(() => App.Models.RemoveRelationship(_selectedModel.Id, item.Relationship.Id));
    }

    private void RelationshipsList_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        var item = RelationshipsList.SelectedItem as RelationshipListItem;
        var selected = item is not null;
        EditRelationshipButton.IsEnabled = selected;
        DeleteRelationshipButton.IsEnabled = selected;
        OpenRelationshipTargetButton.IsEnabled = selected;
        RelationshipDetailsText.Text = item?.Details ?? "Select a relationship to view its details.";
    }

    private void OpenRelationshipTarget_Click(object sender, RoutedEventArgs e)
    {
        if (RelationshipsList.SelectedItem is RelationshipListItem item &&
            CurrentModels().FirstOrDefault(model => model.Id == item.Relationship.TargetModelId) is { } target)
            SelectModel(target);
    }

    private void InboundRelationshipsList_ItemClick(object sender, ItemClickEventArgs e)
    {
        if (e.ClickedItem is InboundRelationshipListItem item && CurrentModels().FirstOrDefault(model => model.Id == item.SourceModelId) is { } source)
            SelectModel(source);
    }

    private async Task ApplyModelChangeAsync(Action change)
    {
        try
        {
            change();
            var selectedId = _selectedModel?.Id;
            RefreshModels();
            if (selectedId is not null && CurrentModels().FirstOrDefault(model => model.Id == selectedId) is { } selected)
                SelectModel(selected);
            else if (_selectedModel is null) ClearModelView();
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
        ExplorerPagesList.ItemsSource = App.Workspace.Current?.Iterations[^1].Pages.OrderBy(page => page.Position).ToArray();
        NoModelInfo.IsOpen = models.Count == 0;
        if (models.Count == 0)
        {
            ClearModelView();
        }
    }

    private void ClearModelView()
    {
        _selectedModel = null;
        ModelsList.SelectedItem = null;
        ModelEditor.Visibility = Visibility.Collapsed;
        NoModelInfo.IsOpen = CurrentModels().Count == 0;
        AddRelationshipButton.IsEnabled = false;
        ModelOptionsText.Text = "Select a model";
        ModelNameText.Text = "";
        ModelTableText.Text = "";
        FieldsList.ItemsSource = null;
        RelationshipsList.ItemsSource = null;
        InboundRelationshipsList.ItemsSource = null;
        FieldDetailsText.Text = "Select a field to view validation.";
        RelationshipDetailsText.Text = "Select a relationship to view its details.";
        EditFieldButton.IsEnabled = false;
        DeleteFieldButton.IsEnabled = false;
        EditRelationshipButton.IsEnabled = false;
        DeleteRelationshipButton.IsEnabled = false;
        OpenRelationshipTargetButton.IsEnabled = false;
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
        RelationshipsList.ItemsSource = model.Relationships.Select(relationship =>
        {
            var target = CurrentModels().FirstOrDefault(candidate => candidate.Id == relationship.TargetModelId)?.Name ?? "Missing model";
            return new RelationshipListItem(relationship, $"{relationship.Type} → {target}",
                $"Target: {target}\nType: {relationship.Type}\nForeign key: {relationship.ForeignKey ?? "Automatic"}\nPivot table: {relationship.PivotTable ?? "Not used"}");
        }).ToArray();
        var incoming = App.Models.GetIncomingReferences(model.Id).Select(reference =>
            new InboundRelationshipListItem(reference.SourceModelId, reference.SourceModelName,
                $"{reference.RelationshipName} ({reference.RelationshipType}) → {model.Name}")).ToArray();
        InboundRelationshipsList.ItemsSource = incoming;
        NoInboundReferencesText.Visibility = incoming.Length == 0 ? Visibility.Visible : Visibility.Collapsed;
        AddRelationshipButton.IsEnabled = true;
        EditFieldButton.IsEnabled = false;
        DeleteFieldButton.IsEnabled = false;
        DeleteRelationshipButton.IsEnabled = false;
        EditRelationshipButton.IsEnabled = false;
        OpenRelationshipTargetButton.IsEnabled = false;
        FieldDetailsText.Text = "Select a field to view validation.";
        RelationshipDetailsText.Text = "Select a relationship to view its details.";
    }

    private IReadOnlyList<ModelDefinition> CurrentModels() => App.Workspace.Current?.Iterations[^1].Models ?? [];

    private async Task<ModelInput?> ShowModelDialogAsync(string title, ModelDefinition? model)
    {
        var name = new TextBox { Header = "Model name (PascalCase)", Text = model?.Name ?? "", PlaceholderText = "CustomerOrder" };
        var table = new TextBox { Header = "Database table (snake_case)", Text = model?.TableName ?? "", PlaceholderText = "customer_orders" };
        name.TextChanged += (_, _) => table.PlaceholderText = ModelDesigner.SuggestedTableName(string.IsNullOrWhiteSpace(name.Text) ? "CustomerOrder" : name.Text);
        var timestamps = new CheckBox { Content = "Add created_at and updated_at", IsChecked = model?.Timestamps ?? true };
        var softDeletes = new CheckBox { Content = "Add soft deletes", IsChecked = model?.SoftDeletes ?? false };
        if (await DialogAsync(title, Panel(name, table, timestamps, softDeletes), "Save") != ContentDialogResult.Primary) return null;
        return new(name.Text, table.Text, timestamps.IsChecked == true, softDeletes.IsChecked == true);
    }

    private async Task<FieldInput?> ShowFieldDialogAsync(string title, FieldDefinition? field)
    {
        var suggestion = _selectedModel is null ? new FieldSuggestion("name", "Name") : ModelDesigner.SuggestedField(_selectedModel);
        var name = new TextBox { Header = "Field name (snake_case)", Text = field?.Name ?? "", PlaceholderText = suggestion.Name };
        var label = new TextBox { Header = "Label", Text = field?.Label ?? "", PlaceholderText = suggestion.Label };
        name.TextChanged += (_, _) => label.PlaceholderText = Humanize(string.IsNullOrWhiteSpace(name.Text) ? suggestion.Name : name.Text);
        var type = Combo("Field type", ModelDesigner.SupportedFieldTypes);
        type.SelectedItem = field?.Type ?? "string";
        var defaultValue = new TextBox { Header = "Default value (optional)", Text = field?.DefaultValue?.ToString() ?? "" };
        var existingRules = field?.ValidationRules ?? [];
        var required = new CheckBox { Content = "Required", IsChecked = existingRules.Contains("required") };
        var email = new CheckBox { Content = "Email", IsChecked = existingRules.Contains("email") };
        var maxEnabled = new CheckBox { Content = "Maximum length/value", IsChecked = existingRules.Any(rule => rule.StartsWith("max:")) };
        var maxValue = new TextBox { Header = "Maximum", Text = existingRules.FirstOrDefault(rule => rule.StartsWith("max:"))?.Split(':').Last() ?? "255" };
        var minEnabled = new CheckBox { Content = "Minimum length/value", IsChecked = existingRules.Any(rule => rule.StartsWith("min:")) };
        var minValue = new TextBox { Header = "Minimum", Text = existingRules.FirstOrDefault(rule => rule.StartsWith("min:"))?.Split(':').Last() ?? "0" };
        var validationButton = new Button { Content = "Add validation", HorizontalAlignment = HorizontalAlignment.Stretch };
        validationButton.Flyout = new Flyout { Content = Panel(required, email, maxEnabled, maxValue, minEnabled, minValue) };
        var nullable = new CheckBox { Content = "Nullable", IsChecked = field?.Nullable ?? false };
        var indexed = new CheckBox { Content = "Indexed", IsChecked = field?.Indexed ?? false };
        var unique = new CheckBox { Content = "Unique (automatically indexed)", IsChecked = field?.Unique ?? false };
        if (await DialogAsync(title, Panel(name, label, type, defaultValue, validationButton, nullable, indexed, unique), "Save") != ContentDialogResult.Primary) return null;
        var rules = new List<string>();
        if (required.IsChecked == true) rules.Add("required");
        if (email.IsChecked == true) rules.Add("email");
        if (minEnabled.IsChecked == true) rules.Add($"min:{minValue.Text}");
        if (maxEnabled.IsChecked == true) rules.Add($"max:{maxValue.Text}");
        return new(name.Text, label.Text, type.SelectedItem!.ToString()!, nullable.IsChecked == true,
            indexed.IsChecked == true, unique.IsChecked == true, defaultValue.Text,
            rules);
    }

    private async Task<RelationshipInput?> ShowRelationshipDialogAsync(string title, RelationshipDefinition? relationship)
    {
        if (_selectedModel is null) return null;
        var targets = CurrentModels().Where(model => model.Id != _selectedModel.Id).ToArray();
        if (targets.Length == 0) { await ShowErrorAsync("Another model required", "Add another model before creating a relationship."); return null; }
        var selectedTarget = targets.FirstOrDefault(model => model.Id == relationship?.TargetModelId) ?? targets[0];
        var name = new TextBox { Header = "Relationship name", Text = relationship?.Name ?? "", PlaceholderText = selectedTarget.Name.ToLowerInvariant() };
        var type = Combo("Relationship type", ModelDesigner.SupportedRelationshipTypes); type.SelectedItem = relationship?.Type ?? "belongs-to";
        var target = new ComboBox { Header = "Target model", ItemsSource = targets, DisplayMemberPath = "Name", SelectedItem = selectedTarget, HorizontalAlignment = HorizontalAlignment.Stretch };
        var foreignKey = new TextBox { Header = "Foreign key (optional)", Text = relationship?.ForeignKey ?? "", PlaceholderText = selectedTarget.Name.ToLowerInvariant() + "_id" };
        var pivotTable = new TextBox { Header = "Pivot table (belongs-to-many)", Text = relationship?.PivotTable ?? "", PlaceholderText = "customer_order" };
        if (await DialogAsync(title, Panel(name, type, target, foreignKey, pivotTable), "Save") != ContentDialogResult.Primary) return null;
        return target.SelectedItem is ModelDefinition targetModel
            ? new(name.Text, type.SelectedItem!.ToString()!, targetModel.Id, foreignKey.Text, pivotTable.Text) : null;
    }

    private static string Humanize(string value)
    {
        var words = value.Replace('_', ' ').Trim().ToLowerInvariant();
        return words.Length == 0 ? "Name" : char.ToUpperInvariant(words[0]) + words[1..];
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

    private async void CloseProject_Click(object sender, RoutedEventArgs e)
    {
        if (!await CanReplaceCurrentProjectAsync()) return;
        ResetWorkspaceView();
    }

    private async void ExitApplication_Click(object sender, RoutedEventArgs e)
    {
        if (await CanReplaceCurrentProjectAsync()) App.MainWindow.Close();
    }

    private async Task<bool> CanReplaceCurrentProjectAsync()
    {
        if (App.Workspace.Current is null) return true;
        if (App.Workspace.IsDirty)
        {
            var result = await new ContentDialog
            {
                XamlRoot = XamlRoot,
                Title = "Save changes?",
                Content = $"Save changes to {App.Workspace.Current.Project.Name} before closing it?",
                PrimaryButtonText = "Save",
                SecondaryButtonText = "Don't save",
                CloseButtonText = "Cancel",
                DefaultButton = ContentDialogButton.Primary
            }.ShowAsync();
            if (result == ContentDialogResult.None) return false;
            if (result == ContentDialogResult.Primary)
            {
                try { await App.Workspace.SaveAsync(); }
                catch (Exception exception) { await ShowErrorAsync("Project could not be saved", exception.Message); return false; }
            }
        }

        App.Workspace.Close();
        return true;
    }

    private void ResetWorkspaceView()
    {
        _selectedModel = null;
        ModelsList.ItemsSource = null;
        ExplorerPagesList.ItemsSource = null;
        FieldsList.ItemsSource = null;
        RelationshipsList.ItemsSource = null;
        InboundRelationshipsList.ItemsSource = null;
        ProjectState.Visibility = Visibility.Collapsed;
        EmptyState.Visibility = Visibility.Visible;
        ExplorerPane.Visibility = Visibility.Collapsed;
        PropertiesPane.Visibility = Visibility.Collapsed;
        SaveButton.IsEnabled = false;
        FileSaveItem.IsEnabled = false;
        FileCloseItem.IsEnabled = false;
        AddModelButton.IsEnabled = false;
        OpenPageDesignerButton.IsEnabled = false;
        StatusText.Text = "Project closed";
        App.MainWindow.Title = "VisualBuilder";
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
    private void OpenPageDesigner_Click(object sender, RoutedEventArgs e)
    {
        var createFirstPage = App.Workspace.Current?.Iterations[^1].Pages.Count == 0;
        Frame.Navigate(typeof(PageDesignerPage), createFirstPage ? PageDesignerPage.CreatePageParameter : null);
    }
    private static void InitializePicker(object picker) => WinRT.Interop.InitializeWithWindow.Initialize(
        picker, WinRT.Interop.WindowNative.GetWindowHandle(App.MainWindow));

    private sealed record RelationshipListItem(RelationshipDefinition Relationship, string Summary, string Details);
    private sealed record InboundRelationshipListItem(Guid SourceModelId, string SourceModel, string Summary);
}
