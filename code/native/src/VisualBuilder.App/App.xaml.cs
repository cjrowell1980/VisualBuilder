using Microsoft.UI.Xaml;
using VisualBuilder.Application.Projects;
using VisualBuilder.Application.Models;
using VisualBuilder.Application.Pages;
using VisualBuilder.Application.Functions;
using VisualBuilder.Domain.Functions;
using VisualBuilder.Infrastructure.Projects;

namespace VisualBuilder.App;

public partial class App : Microsoft.UI.Xaml.Application
{
    public static MainWindow MainWindow { get; private set; } = null!;
    public static IRecentProjectsStore RecentProjects { get; } = new JsonRecentProjectsStore();
    public static ProjectWorkspace Workspace { get; } = new(new JsonProjectDocumentStore(), RecentProjects);
    public static ModelDesigner Models { get; } = new(Workspace);
    public static PageDesigner Pages { get; } = new(Workspace);
    public static FunctionDesigner Functions { get; } = new(Workspace, new FunctionGraphValidator());

    public App() => InitializeComponent();

    protected override void OnLaunched(LaunchActivatedEventArgs args)
    {
        MainWindow = new MainWindow();
        MainWindow.Activate();
    }
}
