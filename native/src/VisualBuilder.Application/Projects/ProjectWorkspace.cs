using VisualBuilder.Domain.Projects;

namespace VisualBuilder.Application.Projects;

public sealed class ProjectWorkspace(IProjectDocumentStore documents, IRecentProjectsStore recentProjects)
{
    public ProjectDocument? Current { get; private set; }
    public string? CurrentPath { get; private set; }
    public bool IsDirty { get; private set; }

    public async Task CreateAsync(NewProjectDefinition definition, string path, CancellationToken cancellationToken = default)
    {
        Current = ProjectDocument.Create(definition);
        CurrentPath = path;
        IsDirty = true;
        await SaveAsync(cancellationToken);
    }

    public async Task OpenAsync(string path, CancellationToken cancellationToken = default)
    {
        var document = await documents.LoadAsync(path, cancellationToken);
        if (document.ContractVersion != ProjectDocument.CurrentContractVersion)
            throw new InvalidDataException($"Project contract version '{document.ContractVersion}' is not supported.");

        Current = document;
        CurrentPath = path;
        IsDirty = false;
        await recentProjects.RecordAsync(new(document.Project.Name, path, DateTimeOffset.UtcNow), cancellationToken);
    }

    public void MarkDirty() => IsDirty = Current is not null;

    public async Task SaveAsync(CancellationToken cancellationToken = default)
    {
        if (Current is null || string.IsNullOrWhiteSpace(CurrentPath)) return;
        Current = Current with { Project = Current.Project with { UpdatedAt = DateTimeOffset.UtcNow } };
        await documents.SaveAsync(CurrentPath, Current, cancellationToken);
        IsDirty = false;
        await recentProjects.RecordAsync(new(Current.Project.Name, CurrentPath, DateTimeOffset.UtcNow), cancellationToken);
    }
}
