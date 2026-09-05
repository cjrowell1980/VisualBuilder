using VisualBuilder.Domain.Functions;

namespace VisualBuilder.Domain.Projects;

public sealed record ProjectDocument(
    Guid Id,
    string Name,
    string Slug,
    ApplicationType ApplicationType,
    StarterKit StarterKit,
    DatabaseEngine Database,
    bool DockerEnabled,
    IReadOnlyList<IterationDefinition> Iterations,
    IReadOnlyList<ModelDefinition> Models,
    IReadOnlyList<PageDefinition> Pages);

public enum ApplicationType { Web, Api, WebApi }
public enum StarterKit { Livewire, Blank, Api }
public enum DatabaseEngine { Sqlite, PostgreSql, MySql, SqlServer }
public enum IterationStatus { Draft, Active, Completed, Archived }

public sealed record IterationDefinition(Guid Id, string Name, int Sequence, IterationStatus Status, Guid? ParentId = null);

public sealed record ModelDefinition(
    Guid Id,
    string Name,
    string TableName,
    IReadOnlyList<FieldDefinition> Fields,
    IReadOnlyList<RelationshipDefinition> Relationships);

public sealed record FieldDefinition(Guid Id, string Name, string Type, bool Nullable = false, bool Indexed = false, bool Unique = false);

public sealed record RelationshipDefinition(Guid Id, string Type, Guid TargetModelId, string? ForeignKey = null);

public sealed record PageDefinition(
    Guid Id,
    string Name,
    string Route,
    IReadOnlyList<ControlDefinition> Controls,
    IReadOnlyList<FunctionGraph> Functions);

public sealed record ControlDefinition(Guid Id, string Type, string Name, IReadOnlyDictionary<string, object?> Properties);
