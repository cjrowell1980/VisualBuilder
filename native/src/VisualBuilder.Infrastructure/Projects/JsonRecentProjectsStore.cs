using System.Text.Json;
using VisualBuilder.Application.Projects;

namespace VisualBuilder.Infrastructure.Projects;

public sealed class JsonRecentProjectsStore : IRecentProjectsStore
{
    private const int MaximumRecentProjects = 10;
    private readonly string _settingsPath;

    public JsonRecentProjectsStore(string? settingsPath = null)
    {
        _settingsPath = settingsPath ?? Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "VisualBuilder", "recent-projects.json");
    }

    public async Task<IReadOnlyList<RecentProject>> LoadAsync(CancellationToken cancellationToken = default)
    {
        if (!File.Exists(_settingsPath)) return [];
        await using var stream = File.OpenRead(_settingsPath);
        return await JsonSerializer.DeserializeAsync<List<RecentProject>>(stream, VisualBuilderJson.Options, cancellationToken) ?? [];
    }

    public async Task RecordAsync(RecentProject project, CancellationToken cancellationToken = default)
    {
        var projects = (await LoadAsync(cancellationToken))
            .Where(recent => !string.Equals(recent.Path, project.Path, StringComparison.OrdinalIgnoreCase))
            .Prepend(project)
            .Take(MaximumRecentProjects)
            .ToArray();

        Directory.CreateDirectory(Path.GetDirectoryName(_settingsPath)!);
        await using var stream = new FileStream(_settingsPath, FileMode.Create, FileAccess.Write, FileShare.None);
        await JsonSerializer.SerializeAsync(stream, projects, VisualBuilderJson.Options, cancellationToken);
    }
}
