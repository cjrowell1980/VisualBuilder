using VisualBuilder.Domain.Projects;

namespace VisualBuilder.Application.Projects;

public interface IProjectDocumentStore
{
    Task<ProjectDocument> LoadAsync(string path, CancellationToken cancellationToken = default);
    Task SaveAsync(string path, ProjectDocument document, CancellationToken cancellationToken = default);
}

public interface IRecentProjectsStore
{
    Task<IReadOnlyList<RecentProject>> LoadAsync(CancellationToken cancellationToken = default);
    Task RecordAsync(RecentProject project, CancellationToken cancellationToken = default);
}

public sealed record RecentProject(string Name, string Path, DateTimeOffset LastOpenedAt);
