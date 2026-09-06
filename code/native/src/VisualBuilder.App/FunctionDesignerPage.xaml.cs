using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Navigation;
using VisualBuilder.Application.Functions;
using VisualBuilder.Domain.Functions;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.App;

public sealed partial class FunctionDesignerPage : Page
{
    private FunctionGraph? _selectedFunction;
    private Guid? _requestedFunctionId;
    private readonly DispatcherTimer _autosaveTimer = new() { Interval = TimeSpan.FromSeconds(30) };
    private IReadOnlyList<FunctionGraph> Functions => App.Workspace.Current?.Iterations[^1].Functions ?? [];
    private IReadOnlyList<PageDefinition> Pages => App.Workspace.Current?.Iterations[^1].Pages ?? [];
    private IReadOnlyList<ModelDefinition> Models => App.Workspace.Current?.Iterations[^1].Models ?? [];

    public FunctionDesignerPage()
    {
        InitializeComponent();
        Loaded += FunctionDesignerPage_Loaded;
        Unloaded += (_, _) => _autosaveTimer.Stop();
        _autosaveTimer.Tick += Autosave_Tick;
        _autosaveTimer.Start();
    }

    private async void FunctionDesignerPage_Loaded(object sender, RoutedEventArgs e)
    {
        RefreshAll();
        if (Functions.Count == 0) await AddFunctionAsync();
    }

    protected override void OnNavigatedTo(NavigationEventArgs e)
    {
        base.OnNavigatedTo(e);
        _requestedFunctionId = e.Parameter is Guid id ? id : null;
    }

    private async void AddFunction_Click(object sender, RoutedEventArgs e)
    {
        await AddFunctionAsync();
    }

    private async Task AddFunctionAsync()
    {
        var input = await ShowFunctionDialogAsync("Add function", null);
        if (input is not null) await ApplyAsync(() => _selectedFunction = App.Functions.AddFunction(input));
    }
    private async void EditFunction_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedFunction is null) return;
        var input = await ShowFunctionDialogAsync("Edit function", _selectedFunction);
        if (input is not null) await ApplyAsync(() => App.Functions.UpdateFunction(_selectedFunction.Id, input));
    }
    private async void DeleteFunction_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedFunction is null || !await ConfirmAsync("Delete function?", $"Delete {_selectedFunction.Name} and its complete block flow?")) return;
        var id = _selectedFunction.Id;
        await ApplyAsync(() => { App.Functions.RemoveFunction(id); _selectedFunction = null; });
    }
    private void FunctionsList_ItemClick(object sender, ItemClickEventArgs e) { if (e.ClickedItem is FunctionGraph graph) SelectFunction(graph); }

    private async void AddBlock_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedFunction is null || sender is not MenuFlyoutItem item || item.Tag is not string value || !Enum.TryParse<FunctionNodeKind>(value, out var kind)) return;
        var config = await ShowBlockDialogAsync($"Add {item.Text.ToLowerInvariant()} block", kind, null);
        if (config is not null) await ApplyAsync(() => App.Functions.AddBlock(_selectedFunction.Id, kind, config));
    }
    private async void EditBlock_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedFunction is null || BlocksList.SelectedItem is not BlockItem item) return;
        var config = await ShowBlockDialogAsync($"Configure {item.Title}", item.Node.Kind, item.Node.Configuration);
        if (config is not null) await ApplyAsync(() => App.Functions.UpdateBlock(_selectedFunction.Id, item.Node.Id, config));
    }
    private async void DeleteBlock_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedFunction is null || BlocksList.SelectedItem is not BlockItem item ||
            !await ConfirmAsync("Delete block?", $"Remove the {item.Title} block from this flow?")) return;
        await ApplyAsync(() => App.Functions.RemoveBlock(_selectedFunction.Id, item.Node.Id));
    }
    private void BlocksList_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        var editable = BlocksList.SelectedItem is BlockItem item && item.Node.Kind is not (FunctionNodeKind.Event or FunctionNodeKind.Return);
        EditBlockButton.IsEnabled = editable;
        DeleteBlockButton.IsEnabled = editable;
    }

    private async void BindEvent_Click(object sender, RoutedEventArgs e)
    {
        if (_selectedFunction is null || Pages.Count == 0) { await ShowErrorAsync("Page required", "Add a page before binding this function."); return; }
        var pageChoices = _selectedFunction.Scope == FunctionScope.Page ? Pages.Where(page => page.Id == _selectedFunction.PageId).ToArray() : Pages.ToArray();
        var page = new ComboBox { Header = "Page", ItemsSource = pageChoices, DisplayMemberPath = "Name", SelectedIndex = 0, HorizontalAlignment = HorizontalAlignment.Stretch };
        var control = new ComboBox { Header = "Control (optional)", HorizontalAlignment = HorizontalAlignment.Stretch };
        void RefreshControls()
        {
            var selectedPage = page.SelectedItem as PageDefinition;
            var choices = new[] { new ControlChoice(null, "Page") }.Concat(selectedPage?.Controls.Select(item => new ControlChoice(item.Id, item.Label)) ?? []).ToArray();
            control.ItemsSource = choices; control.DisplayMemberPath = "Name"; control.SelectedIndex = 0;
        }
        page.SelectionChanged += (_, _) => RefreshControls(); RefreshControls();
        var eventName = Combo("Event", "page-loaded", "submitted", "clicked", "changed");
        if (await DialogAsync("Bind function event", Panel(page, control, eventName), "Bind") != ContentDialogResult.Primary) return;
        if (page.SelectedItem is PageDefinition selectedPage && control.SelectedItem is ControlChoice selectedControl)
            await ApplyAsync(() => App.Functions.BindEvent(selectedPage.Id, selectedControl.Id, eventName.SelectedItem!.ToString()!, _selectedFunction.Id));
    }

    private async Task ApplyAsync(Action change)
    {
        try
        {
            var id = _selectedFunction?.Id; change(); id = _selectedFunction?.Id ?? id; RefreshAll();
            if (id is not null && Functions.FirstOrDefault(item => item.Id == id) is { } graph) SelectFunction(graph);
            else ClearFunction();
            StatusText.Text = "Unsaved changes — autosave pending";
        }
        catch (FunctionDesignException exception) { await ShowErrorAsync("Function change rejected", exception.Message); }
    }

    private void RefreshAll()
    {
        ExplorerModelsList.ItemsSource = Models; ExplorerPagesList.ItemsSource = Pages;
        FunctionsList.ItemsSource = null; FunctionsList.ItemsSource = Functions;
        if (Functions.Count == 0) ClearFunction();
        else if (_selectedFunction is null)
            SelectFunction((_requestedFunctionId is Guid id ? Functions.FirstOrDefault(item => item.Id == id) : null) ?? Functions[0]);
        _requestedFunctionId = null;
    }
    private void SelectFunction(FunctionGraph graph)
    {
        _selectedFunction = graph; FunctionsList.SelectedItem = graph; NoFunctionText.Visibility = Visibility.Collapsed;
        FunctionEditor.Visibility = Visibility.Visible; FunctionNameText.Text = graph.Name;
        FunctionSummaryText.Text = $"{graph.Scope} function • {graph.Nodes.Count} blocks";
        FunctionPropertiesText.Text = $"Scope: {graph.Scope}\nPage: {Pages.FirstOrDefault(page => page.Id == graph.PageId)?.Name ?? "All project pages"}";
        BindEventButton.IsEnabled = true;
        BlocksList.ItemsSource = OrderedNodes(graph).Select(node => new BlockItem(node, Display(node.Kind), Format(node.Configuration))).ToArray();
        var issues = App.Functions.Validate(graph.Id); ValidationIssuesList.ItemsSource = issues;
        ValidationInfo.IsOpen = true; ValidationInfo.Severity = issues.Count == 0 ? InfoBarSeverity.Success : InfoBarSeverity.Error;
        ValidationInfo.Message = issues.Count == 0 ? "Function graph is valid." : $"{issues.Count} issue(s) must be fixed before generation.";
    }
    private void ClearFunction()
    {
        _selectedFunction = null; FunctionEditor.Visibility = Visibility.Collapsed; NoFunctionText.Visibility = Visibility.Visible;
        FunctionPropertiesText.Text = "No function selected"; BindEventButton.IsEnabled = false; ValidationInfo.IsOpen = false;
        ValidationIssuesList.ItemsSource = null; BlocksList.ItemsSource = null;
    }

    private async Task<FunctionInput?> ShowFunctionDialogAsync(string title, FunctionGraph? graph)
    {
        var name = new TextBox { Header = "Function name", Text = graph?.Name ?? "", PlaceholderText = "Save customer" };
        var scope = Combo("Scope", "Page", "Project"); scope.SelectedItem = graph?.Scope.ToString() ?? (Pages.Count == 0 ? "Project" : "Page");
        var pageChoices = new[] { new PageChoice(null, "Select a page") }.Concat(Pages.Select(page => new PageChoice(page.Id, page.Name))).ToArray();
        var page = new ComboBox { Header = "Page", ItemsSource = pageChoices, DisplayMemberPath = "Name", HorizontalAlignment = HorizontalAlignment.Stretch };
        page.SelectedItem = pageChoices.FirstOrDefault(item => item.Id == graph?.PageId) ?? pageChoices[0];
        void UpdatePageAvailability() => page.IsEnabled = Equals(scope.SelectedItem?.ToString(), "Page");
        scope.SelectionChanged += (_, _) => UpdatePageAvailability(); UpdatePageAvailability();
        var eventName = Combo("Entry event", "clicked", "submitted", "changed", "page-loaded");
        var currentEvent = graph?.Nodes.FirstOrDefault(node => node.Kind == FunctionNodeKind.Event)?.Configuration.GetValueOrDefault("event")?.ToString();
        eventName.SelectedItem = currentEvent ?? "clicked";
        if (await DialogAsync(title, Panel(name, scope, page, eventName), "Save") != ContentDialogResult.Primary) return null;
        var selectedScope = Enum.Parse<FunctionScope>(scope.SelectedItem!.ToString()!);
        return new(name.Text, selectedScope, selectedScope == FunctionScope.Page ? ((PageChoice)page.SelectedItem).Id : null, eventName.SelectedItem!.ToString()!);
    }
    private async Task<IReadOnlyDictionary<string, object?>?> ShowBlockDialogAsync(string title, FunctionNodeKind kind, IReadOnlyDictionary<string, object?>? current)
    {
        var model = new ComboBox { Header = "Model", ItemsSource = Models, DisplayMemberPath = "Name", HorizontalAlignment = HorizontalAlignment.Stretch };
        if (current?.GetValueOrDefault("modelId") is { } modelId) model.SelectedItem = Models.FirstOrDefault(item => item.Id.ToString() == modelId.ToString());
        var value = new TextBox { Header = BlockValueHeader(kind), Text = current?.GetValueOrDefault("value")?.ToString() ?? "", PlaceholderText = BlockPlaceholder(kind) };
        var extra = new TextBox { Header = "Extra configuration (key=value, comma separated)", Text = Format(current ?? new Dictionary<string, object?>(), ["modelId", "value"]) };
        if (await DialogAsync(title, Panel(model, value, extra), "Save") != ContentDialogResult.Primary) return null;
        var result = Parse(extra.Text); if (model.SelectedItem is ModelDefinition selected) result["modelId"] = selected.Id.ToString();
        if (!string.IsNullOrWhiteSpace(value.Text)) result["value"] = value.Text.Trim(); return result;
    }

    private static IReadOnlyList<FunctionNode> OrderedNodes(FunctionGraph graph)
    {
        var result = new List<FunctionNode>(); var current = graph.Nodes.FirstOrDefault(node => node.Kind == FunctionNodeKind.Event);
        var seen = new HashSet<Guid>();
        while (current is not null && seen.Add(current.Id)) { result.Add(current); var next = graph.Edges.FirstOrDefault(edge => edge.SourceNodeId == current.Id); current = next is null ? null : graph.Nodes.FirstOrDefault(node => node.Id == next.TargetNodeId); }
        result.AddRange(graph.Nodes.Where(node => !seen.Contains(node.Id))); return result;
    }
    private static string BlockValueHeader(FunctionNodeKind kind) => kind switch { FunctionNodeKind.Validate => "Validation rules", FunctionNodeKind.Authorize => "Ability / policy", FunctionNodeKind.Notify => "Message", FunctionNodeKind.Navigate => "Route", FunctionNodeKind.Condition => "Condition", _ => "Value / operation" };
    private static string BlockPlaceholder(FunctionNodeKind kind) => kind switch { FunctionNodeKind.Validate => "required,email", FunctionNodeKind.Authorize => "update", FunctionNodeKind.Notify => "Saved successfully", FunctionNodeKind.Navigate => "customers.index", _ => "Configure this block" };
    private static string Display(FunctionNodeKind kind) => string.Concat(kind.ToString().Select((character, index) => index > 0 && char.IsUpper(character) ? " " + character : character.ToString()));
    private static string Format(IReadOnlyDictionary<string, object?> values, IEnumerable<string>? exclude = null) => string.Join(", ", values.Where(item => exclude?.Contains(item.Key) != true).Select(item => $"{item.Key}={item.Value}"));
    private static Dictionary<string, object?> Parse(string value) => value.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries).Select(item => item.Split('=', 2, StringSplitOptions.TrimEntries)).Where(parts => parts.Length == 2).ToDictionary(parts => parts[0], parts => (object?)parts[1], StringComparer.OrdinalIgnoreCase);
    private static ComboBox Combo(string header, params string[] values) { var combo = new ComboBox { Header = header, HorizontalAlignment = HorizontalAlignment.Stretch, SelectedIndex = 0 }; foreach (var value in values) combo.Items.Add(value); return combo; }
    private static StackPanel Panel(params UIElement[] children) { var panel = new StackPanel { Spacing = 12 }; foreach (var child in children) panel.Children.Add(child); return panel; }
    private async Task<ContentDialogResult> DialogAsync(string title, object content, string primary) => await new ContentDialog { XamlRoot = XamlRoot, Title = title, Content = content, PrimaryButtonText = primary, CloseButtonText = "Cancel", DefaultButton = ContentDialogButton.Primary }.ShowAsync();
    private async Task<bool> ConfirmAsync(string title, string content) => await new ContentDialog { XamlRoot = XamlRoot, Title = title, Content = content, PrimaryButtonText = "Delete", CloseButtonText = "Cancel" }.ShowAsync() == ContentDialogResult.Primary;
    private async Task ShowErrorAsync(string title, string content) => await new ContentDialog { XamlRoot = XamlRoot, Title = title, Content = content, CloseButtonText = "Close" }.ShowAsync();
    private async void Save_Click(object sender, RoutedEventArgs e) { try { await App.Workspace.SaveAsync(); StatusText.Text = $"Saved at {DateTime.Now:t}"; } catch (Exception exception) { await ShowErrorAsync("Project could not be saved", exception.Message); } }
    private async void Autosave_Tick(object? sender, object e) { if (App.Workspace.IsDirty) await App.Workspace.SaveAsync(); }
    private void Models_Click(object sender, RoutedEventArgs e) => Frame.Navigate(typeof(MainPage));
    private void Pages_Click(object sender, RoutedEventArgs e) => Frame.Navigate(typeof(PageDesignerPage));
    private void ExplorerModelsList_ItemClick(object sender, ItemClickEventArgs e) { if (e.ClickedItem is ModelDefinition model) Frame.Navigate(typeof(MainPage), model.Id); }
    private void ExplorerPagesList_ItemClick(object sender, ItemClickEventArgs e) { if (e.ClickedItem is PageDefinition page) Frame.Navigate(typeof(PageDesignerPage), page.Id); }
    private async void CloseProject_Click(object sender, RoutedEventArgs e) { if (App.Workspace.IsDirty) await App.Workspace.SaveAsync(); App.Workspace.Close(); Frame.Navigate(typeof(MainPage)); }
    private async void ExitApplication_Click(object sender, RoutedEventArgs e) { if (App.Workspace.IsDirty) await App.Workspace.SaveAsync(); App.MainWindow.Close(); }
    private sealed record BlockItem(FunctionNode Node, string Title, string Configuration);
    private sealed record PageChoice(Guid? Id, string Name);
    private sealed record ControlChoice(Guid? Id, string Name);
}
