using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using VisualBuilder.Application.Pages;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.App;

public sealed partial class PageDesignerPage : Page
{
    private PageDefinition? _selectedPage;
    private readonly DispatcherTimer _autosaveTimer = new() { Interval = TimeSpan.FromSeconds(30) };

    public PageDesignerPage()
    {
        InitializeComponent();
        Loaded += (_, _) => RefreshPages();
        Unloaded += (_, _) => _autosaveTimer.Stop();
        _autosaveTimer.Tick += Autosave_Tick;
        _autosaveTimer.Start();
    }

    private IReadOnlyList<PageDefinition> Pages => App.Workspace.Current?.Iterations[^1].Pages ?? [];
    private IReadOnlyList<ModelDefinition> Models => App.Workspace.Current?.Iterations[^1].Models ?? [];

    private async void AddPage_Click(object sender, RoutedEventArgs e)
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
        if (e.ClickedItem is PageDefinition page) SelectPage(page);
    }

    private async void AddControl_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedPage is null) return;
        var input = await ShowControlDialogAsync("Add control", null);
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
        PagesList.ItemsSource = Pages;
        NoPageText.Visibility = Pages.Count == 0 ? Visibility.Visible : Visibility.Collapsed;
        if (Pages.Count == 0) PageEditor.Visibility = Visibility.Collapsed;
    }

    private void SelectPage(PageDefinition page)
    {
        _selectedPage = page;
        PagesList.SelectedItem = page;
        NoPageText.Visibility = Visibility.Collapsed;
        PageEditor.Visibility = Visibility.Visible;
        PageNameText.Text = page.Name;
        PageSummaryText.Text = $"/{page.Slug} • {page.Type}";
        var modelName = Models.FirstOrDefault(model => model.Id == page.ModelId)?.Name ?? "No model";
        PagePropertiesText.Text = $"Layout: {page.Layout}\nModel: {modelName}\nPosition: {page.Position + 1}";
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
        if (await DialogAsync(title, Panel(name, slug, type, layout, model), "Save") != ContentDialogResult.Primary) return null;
        return new(name.Text, slug.Text, type.SelectedItem!.ToString()!, layout.SelectedItem!.ToString()!, ((ModelChoice)model.SelectedItem).Id);
    }

    private async Task<ControlInput?> ShowControlDialogAsync(string title, ControlDefinition? control)
    {
        var type = Combo("Control type", PageDesigner.SupportedControlTypes); type.SelectedItem = control?.Type ?? "input";
        var label = new TextBox { Header = "Label", Text = control?.Label ?? "", PlaceholderText = "Company name" };
        var width = Combo("Width", PageDesigner.SupportedWidths); width.SelectedItem = control?.Width ?? "full";
        var fields = new[] { new FieldChoice(null, "No field") };
        if (_selectedPage?.ModelId is Guid modelId)
            fields = fields.Concat(Models.First(model => model.Id == modelId).Fields.Select(field => new FieldChoice(field.Id, field.Label))).ToArray();
        var field = new ComboBox { Header = "Bound field", ItemsSource = fields, DisplayMemberPath = "Name", HorizontalAlignment = HorizontalAlignment.Stretch };
        field.SelectedItem = fields.FirstOrDefault(choice => choice.Id == control?.FieldId) ?? fields[0];
        var configuration = new TextBox { Header = "Configuration (key=value, comma separated)", PlaceholderText = "placeholder=Enter a name", Text = FormatConfiguration(control?.Configuration) };
        if (await DialogAsync(title, Panel(type, label, width, field, configuration), "Save") != ContentDialogResult.Primary) return null;
        return new(type.SelectedItem!.ToString()!, label.Text, width.SelectedItem!.ToString()!, ((FieldChoice)field.SelectedItem).Id,
            ParseConfiguration(configuration.Text));
    }

    private async void Save_Click(object sender, RoutedEventArgs e) => await SaveAsync();
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

    private sealed record ModelChoice(Guid? Id, string Name);
    private sealed record FieldChoice(Guid? Id, string Name);
    private sealed record ControlListItem(ControlDefinition Control, string Binding);
}
