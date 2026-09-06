using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Navigation;
using VisualBuilder.Application.Pages;
using VisualBuilder.Domain.Projects;
using VisualBuilder.Domain.Functions;

namespace VisualBuilder.App;

public sealed partial class PageDesignerPage : Page
{
    public const string CreatePageParameter = "create-page";
    private PageDefinition? _selectedPage;
    private Guid? _requestedPageId;
    private bool _createPageOnLoad;
    private readonly DispatcherTimer _autosaveTimer = new() { Interval = TimeSpan.FromSeconds(30) };

    public PageDesignerPage()
    {
        InitializeComponent();
        Loaded += PageDesignerPage_Loaded;
        Unloaded += (_, _) => _autosaveTimer.Stop();
        _autosaveTimer.Tick += Autosave_Tick;
        _autosaveTimer.Start();
    }

    protected override void OnNavigatedTo(NavigationEventArgs e)
    {
        base.OnNavigatedTo(e);
        _requestedPageId = e.Parameter is Guid id ? id : null;
        _createPageOnLoad = Equals(e.Parameter, CreatePageParameter);
    }

    private async void PageDesignerPage_Loaded(object sender, RoutedEventArgs e)
    {
        RefreshPages();
        if (_requestedPageId is Guid id && Pages.FirstOrDefault(page => page.Id == id) is { } page) SelectPage(page);
        if (_createPageOnLoad && Pages.Count == 0)
        {
            _createPageOnLoad = false;
            await AddPageAsync();
        }
    }

    private IReadOnlyList<PageDefinition> Pages => App.Workspace.Current?.Iterations[^1].Pages ?? [];
    private IReadOnlyList<ModelDefinition> Models => App.Workspace.Current?.Iterations[^1].Models ?? [];

    private async void AddPage_Click(object sender, RoutedEventArgs e)
    {
        await AddPageAsync();
    }

    private async Task AddPageAsync()
    {
        var input = await ShowPageDialogAsync("Add page", null);
        if (input is null) return;
        await ApplyAsync(() => _selectedPage = App.Pages.AddPage(input));
    }

    private async void EditPage_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedPage is null) return;
        var input = await ShowPageDialogAsync("Edit page", _selectedPage);
        if (input is null) return;
        var id = _selectedPage.Id;
        await ApplyAsync(() => App.Pages.UpdatePage(id, input));
    }

    private async void DeletePage_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedPage is null || !await ConfirmAsync("Delete page?", $"Delete {_selectedPage.Name} and all of its controls?")) return;
        var id = _selectedPage.Id;
        await ApplyAsync(() => { App.Pages.RemovePage(id); _selectedPage = null; });
    }

    private void PagesList_ItemClick(object sender, ItemClickEventArgs e)
    {
        if (e.ClickedItem is PageExplorerItem item) SelectPage(item.Page);
    }

    private void ExplorerModelsList_ItemClick(object sender, ItemClickEventArgs e)
    {
        if (e.ClickedItem is ModelDefinition model) Frame.Navigate(typeof(MainPage), model.Id);
    }

    private void OpenFunctionDesigner_Click(object sender, RoutedEventArgs e) => Frame.Navigate(typeof(FunctionDesignerPage));
    private void ExplorerFunctionsList_ItemClick(object sender, ItemClickEventArgs e)
    {
        if (e.ClickedItem is FunctionGraph graph) Frame.Navigate(typeof(FunctionDesignerPage), graph.Id);
    }

    private async void AddControl_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedPage is null) return;
        var input = await ShowControlDialogAsync("Add control", null);
        if (input is not null) await ApplyAsync(() => App.Pages.AddControl(_selectedPage.Id, input));
    }

    private async void AddTypedContent_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedPage is null || sender is not MenuFlyoutItem item || item.Tag is not string type) return;
        var input = await ShowControlDialogAsync($"Add {item.Text.ToLowerInvariant()}", null, type);
        if (input is not null) await ApplyAsync(() => App.Pages.AddControl(_selectedPage.Id, input));
    }

    private async void EditControl_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedPage is null || ControlsList.SelectedItem is not ControlListItem item) return;
        var input = await ShowControlDialogAsync("Edit control", item.Control);
        if (input is not null) await ApplyAsync(() => App.Pages.UpdateControl(_selectedPage.Id, item.Control.Id, input));
    }

    private async void DeleteControl_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedPage is null || ControlsList.SelectedItem is not ControlListItem item ||
            !await ConfirmAsync("Delete control?", $"Delete the {item.Control.Label} control?")) return;
        await ApplyAsync(() => App.Pages.RemoveControl(_selectedPage.Id, item.Control.Id));
    }

    private async void MoveControlUp_Click(object sender, RoutedEventArgs e) => await MoveSelectedControlAsync(-1);
    private async void MoveControlDown_Click(object sender, RoutedEventArgs e) => await MoveSelectedControlAsync(1);

    private async Task MoveSelectedControlAsync(int offset)
    {
        if (_selectedPage is null || ControlsList.SelectedItem is not ControlListItem item) return;
        await ApplyAsync(() => App.Pages.MoveControl(_selectedPage.Id, item.Control.Id, offset));
        ControlsList.SelectedItem = ControlsList.Items.Cast<ControlListItem>().FirstOrDefault(current => current.Control.Id == item.Control.Id);
    }

    private void ControlsList_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        var selected = ControlsList.SelectedItem is ControlListItem;
        EditControlButton.IsEnabled = selected;
        DeleteControlButton.IsEnabled = selected;
        MoveUpButton.IsEnabled = selected;
        MoveDownButton.IsEnabled = selected;
    }

    private async Task ApplyAsync(Action change)
    {
        try
        {
            var selectedId = _selectedPage?.Id;
            change();
            selectedId = _selectedPage?.Id ?? selectedId;
            RefreshPages();
            if (selectedId is not null && Pages.FirstOrDefault(page => page.Id == selectedId) is { } selected) SelectPage(selected);
            StatusText.Text = "Unsaved changes — autosave pending";
        }
        catch (PageDesignException exception)
        {
            await ShowErrorAsync("Page change rejected", exception.Message);
        }
    }

    private void RefreshPages()
    {
        PagesList.ItemsSource = null;
        PagesList.ItemsSource = Pages.OrderBy(page => page.Position).Select(page =>
            new PageExplorerItem(page, ExplorerName(page), ExplorerPath(page))).ToArray();
        ExplorerModelsList.ItemsSource = Models;
        ExplorerFunctionsList.ItemsSource = App.Workspace.Current?.Iterations[^1].Functions;
        NoPageText.Visibility = Pages.Count == 0 ? Visibility.Visible : Visibility.Collapsed;
        if (Pages.Count == 0) PageEditor.Visibility = Visibility.Collapsed;
    }

    private void SelectPage(PageDefinition page)
    {
        _selectedPage = page;
        PagesList.SelectedItem = PagesList.Items.Cast<PageExplorerItem>().FirstOrDefault(item => item.Page.Id == page.Id);
        NoPageText.Visibility = Visibility.Collapsed;
        PageEditor.Visibility = Visibility.Visible;
        PageNameText.Text = page.Name;
        PageSummaryText.Text = $"/{page.Slug} • {page.Type}";
        var modelName = Models.FirstOrDefault(model => model.Id == page.ModelId)?.Name ?? "No model";
        PagePropertiesText.Text = $"Layout: {page.Layout}\nModel: {modelName}\nCategory: {page.Category ?? "None"}\nParent: {Pages.FirstOrDefault(item => item.Id == page.ParentPageId)?.Name ?? "None"}\nPosition: {page.Position + 1}";
        var model = Models.FirstOrDefault(candidate => candidate.Id == page.ModelId);
        ControlsList.ItemsSource = page.Controls.OrderBy(control => control.Position)
            .Select(control => new ControlListItem(control,
                control.FieldId is null ? "Not field-bound" : model?.Fields.FirstOrDefault(field => field.Id == control.FieldId)?.Name ?? "Missing field"))
            .ToArray();
        ControlsList.SelectedItem = null;
    }

    private async Task<PageInput?> ShowPageDialogAsync(string title, PageDefinition? page)
    {
        var name = new TextBox { Header = "Page name", Text = page?.Name ?? "", PlaceholderText = "Customer list" };
        var slug = new TextBox { Header = "Route slug", Text = page?.Slug ?? "", PlaceholderText = "customer-list" };
        var type = Combo("Page type", PageDesigner.SupportedPageTypes); type.SelectedItem = page?.Type ?? "index";
        var layout = Combo("Layout", PageDesigner.SupportedLayouts); layout.SelectedItem = page?.Layout ?? "app";
        var modelChoices = new[] { new ModelChoice(null, "No model") }.Concat(Models.Select(model => new ModelChoice(model.Id, model.Name))).ToArray();
        var model = new ComboBox { Header = "Bound model", ItemsSource = modelChoices, DisplayMemberPath = "Name", HorizontalAlignment = HorizontalAlignment.Stretch };
        model.SelectedItem = modelChoices.FirstOrDefault(choice => choice.Id == page?.ModelId) ?? modelChoices[0];
        var category = new TextBox { Header = "Category path (optional)", Text = page?.Category ?? "", PlaceholderText = "Sales / Orders" };
        var parentChoices = new[] { new PageChoice(null, "No parent page") }.Concat(Pages.Where(item => item.Id != page?.Id).Select(item => new PageChoice(item.Id, item.Name))).ToArray();
        var parent = new ComboBox { Header = "Parent page (optional)", ItemsSource = parentChoices, DisplayMemberPath = "Name", HorizontalAlignment = HorizontalAlignment.Stretch };
        parent.SelectedItem = parentChoices.FirstOrDefault(choice => choice.Id == page?.ParentPageId) ?? parentChoices[0];
        if (await DialogAsync(title, Panel(name, slug, type, layout, model, category, parent), "Save") != ContentDialogResult.Primary) return null;
        return new(name.Text, slug.Text, type.SelectedItem!.ToString()!, layout.SelectedItem!.ToString()!, ((ModelChoice)model.SelectedItem).Id,
            category.Text, ((PageChoice)parent.SelectedItem).Id);
    }

    private async Task<ControlInput?> ShowControlDialogAsync(string title, ControlDefinition? control, string? initialType = null)
    {
        var type = Combo("Content type", PageDesigner.SupportedControlTypes); type.SelectedItem = control?.Type ?? initialType ?? "input";
        var label = new TextBox { Header = "Label", Text = control?.Label ?? "", PlaceholderText = "Company name" };
        var width = Combo("Width", PageDesigner.SupportedWidths); width.SelectedItem = control?.Width ?? "full";
        var fields = new[] { new FieldChoice(null, "No field") };
        if (_selectedPage?.ModelId is Guid modelId)
            fields = fields.Concat(Models.First(model => model.Id == modelId).Fields.Select(field => new FieldChoice(field.Id, field.Label))).ToArray();
        var field = new ComboBox { Header = "Bound field", ItemsSource = fields, DisplayMemberPath = "Name", HorizontalAlignment = HorizontalAlignment.Stretch };
        field.SelectedItem = fields.FirstOrDefault(choice => choice.Id == control?.FieldId) ?? fields[0];
        var placeholder = new TextBox { Header = "Placeholder", Text = ConfigValue(control, "placeholder"), PlaceholderText = "Enter a value" };
        var options = new TextBox { Header = "Options (comma separated)", Text = ConfigValue(control, "options"), PlaceholderText = "Draft, Active, Archived" };
        var action = new TextBox { Header = "Action / destination", Text = ConfigValue(control, "action"), PlaceholderText = "save" };
        var columns = new TextBox { Header = "Table columns (comma separated)", Text = ConfigValue(control, "columns"), PlaceholderText = "Name, Email, Status" };
        var style = new TextBox { Header = "Style / level", Text = ConfigValue(control, "style"), PlaceholderText = "primary or h2" };
        var configuration = new TextBox { Header = "Extra configuration (key=value)", Text = ExtraConfiguration(control), PlaceholderText = "help=Shown below the field" };
        if (await DialogAsync(title, Panel(type, label, width, field, placeholder, options, action, columns, style, configuration), "Save") != ContentDialogResult.Primary) return null;
        var config = ParseConfiguration(configuration.Text).ToDictionary(item => item.Key, item => item.Value, StringComparer.OrdinalIgnoreCase);
        AddConfig(config, "placeholder", placeholder.Text); AddConfig(config, "options", options.Text);
        AddConfig(config, "action", action.Text); AddConfig(config, "columns", columns.Text); AddConfig(config, "style", style.Text);
        return new(type.SelectedItem!.ToString()!, label.Text, width.SelectedItem!.ToString()!, ((FieldChoice)field.SelectedItem).Id,
            config);
    }

    private async void Save_Click(object sender, RoutedEventArgs e) => await SaveAsync();
    private async void CloseProject_Click(object sender, RoutedEventArgs e)
    {
        if (App.Workspace.IsDirty && !await SaveAsync()) return;
        App.Workspace.Close();
        Frame.Navigate(typeof(MainPage));
    }
    private async void ExitApplication_Click(object sender, RoutedEventArgs e)
    {
        if (App.Workspace.IsDirty && !await SaveAsync()) return;
        App.MainWindow.Close();
    }
    private async void Autosave_Tick(object? sender, object e) { if (App.Workspace.IsDirty) await SaveAsync(); }
    private async void Back_Click(object sender, RoutedEventArgs e)
    {
        if (App.Workspace.IsDirty && !await SaveAsync()) return;
        Frame.GoBack();
    }

    private async Task<bool> SaveAsync()
    {
        try { await App.Workspace.SaveAsync(); StatusText.Text = $"Saved at {DateTime.Now:t}"; return true; }
        catch (Exception exception) { await ShowErrorAsync("Project could not be saved", exception.Message); return false; }
    }

    private async Task<ContentDialogResult> DialogAsync(string title, object content, string primaryText) => await new ContentDialog
    { XamlRoot = XamlRoot, Title = title, Content = content, PrimaryButtonText = primaryText, CloseButtonText = "Cancel", DefaultButton = ContentDialogButton.Primary }.ShowAsync();
    private async Task<bool> ConfirmAsync(string title, string content) => await new ContentDialog
    { XamlRoot = XamlRoot, Title = title, Content = content, PrimaryButtonText = "Delete", CloseButtonText = "Cancel", DefaultButton = ContentDialogButton.Close }.ShowAsync() == ContentDialogResult.Primary;
    private async Task ShowErrorAsync(string title, string content) => await new ContentDialog
    { XamlRoot = XamlRoot, Title = title, Content = content, CloseButtonText = "Close" }.ShowAsync();

    private static ComboBox Combo(string header, IEnumerable<string> values)
    { var combo = new ComboBox { Header = header, HorizontalAlignment = HorizontalAlignment.Stretch, SelectedIndex = 0 }; foreach (var value in values) combo.Items.Add(value); return combo; }
    private static StackPanel Panel(params UIElement[] children)
    { var panel = new StackPanel { Spacing = 12 }; foreach (var child in children) panel.Children.Add(child); return panel; }
    private static IReadOnlyDictionary<string, object?> ParseConfiguration(string value) => value.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
        .Select(item => item.Split('=', 2, StringSplitOptions.TrimEntries)).Where(parts => parts.Length == 2 && parts[0].Length > 0)
        .ToDictionary(parts => parts[0], parts => (object?)parts[1], StringComparer.OrdinalIgnoreCase);
    private static string FormatConfiguration(IReadOnlyDictionary<string, object?>? configuration) => configuration is null ? "" : string.Join(", ", configuration.Select(item => $"{item.Key}={item.Value}"));
    private static string ConfigValue(ControlDefinition? control, string key) => control?.Configuration.TryGetValue(key, out var value) == true ? value?.ToString() ?? "" : "";
    private static string ExtraConfiguration(ControlDefinition? control) => control is null ? "" : string.Join(", ", control.Configuration
        .Where(item => item.Key is not ("placeholder" or "options" or "action" or "columns" or "style")).Select(item => $"{item.Key}={item.Value}"));
    private static void AddConfig(IDictionary<string, object?> config, string key, string value) { if (!string.IsNullOrWhiteSpace(value)) config[key] = value.Trim(); }
    private string ExplorerName(PageDefinition page)
    {
        var depth = 0; var parentId = page.ParentPageId;
        while (parentId is not null && depth < Pages.Count) { depth++; parentId = Pages.FirstOrDefault(item => item.Id == parentId)?.ParentPageId; }
        return $"{new string(' ', depth * 3)}{page.Name}";
    }
    private string ExplorerPath(PageDefinition page) => string.IsNullOrWhiteSpace(page.Category) ? $"/{page.Slug}" : $"{page.Category} / {page.Slug}";

    private sealed record ModelChoice(Guid? Id, string Name);
    private sealed record PageChoice(Guid? Id, string Name);
    private sealed record FieldChoice(Guid? Id, string Name);
    private sealed record ControlListItem(ControlDefinition Control, string Binding);
    private sealed record PageExplorerItem(PageDefinition Page, string DisplayName, string Path);
}
