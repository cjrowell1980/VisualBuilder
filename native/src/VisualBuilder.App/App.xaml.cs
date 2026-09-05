using Microsoft.UI.Xaml;
using VisualBuilder.Application.Projects;
using VisualBuilder.Infrastructure.Projects;

namespace VisualBuilder.App;

public partial class App : Microsoft.UI.Xaml.Application
{
    public static MainWindow MainWindow { get; private set; } = null!;
    public static IRecentProjectsStore RecentProjects { get; } = new JsonRecentProjectsStore();
    public static ProjectWorkspace Workspace { get; } = new(new JsonProjectDocumentStore(), RecentProjects);

    public App() => InitializeComponent();

    protected override void OnLaunched(LaunchActivatedEventArgs args)
    {
        MainWindow = new MainWindow();
        MainWindow.Activate();
    }
}
