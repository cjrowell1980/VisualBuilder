using VisualBuilder.Domain.Functions;
using System.Globalization;
using System.Text;

namespace VisualBuilder.Domain.Projects;

public sealed record ProjectDocument(string ContractVersion, ProjectSettings Project, IReadOnlyList<IterationDefinition> Iterations)
{
    public const string CurrentContractVersion = "1.0";

    public static ProjectDocument Create(NewProjectDefinition definition, DateTimeOffset? now = null)
    {
        if (string.IsNullOrWhiteSpace(definition.Name))
            throw new ArgumentException("A project name is required.", nameof(definition));

        var timestamp = now ?? DateTimeOffset.UtcNow;
        return new(CurrentContractVersion,
            new(Guid.NewGuid(), definition.Name.Trim(), Slug.Create(definition.Name), definition.Description?.Trim(),
                definition.ApplicationType, definition.StarterKit, definition.Database, definition.DockerEnabled,
                definition.OutputPath, timestamp, timestamp),
            [new(Guid.NewGuid(), null, 1, "Initial iteration", IterationStatus.Draft, [], [], [], [])]);
    }
}

public sealed record NewProjectDefinition(string Name, ApplicationType ApplicationType, StarterKit StarterKit,
    DatabaseEngine Database, bool DockerEnabled, string? Description = null, string? OutputPath = null);

public sealed record ProjectSettings(Guid Id, string Name, string Slug, string? Description,
    ApplicationType ApplicationType, StarterKit StarterKit, DatabaseEngine Database, bool DockerEnabled,
    string? OutputPath, DateTimeOffset CreatedAt, DateTimeOffset UpdatedAt);

public enum ApplicationType { Web, Api, WebApi }
public enum StarterKit { Livewire, Blank, Api }
public enum DatabaseEngine { PostgreSql, MySql, Sqlite }
public enum IterationStatus { Draft, Validated, Generated, Assembled, Published }

public sealed record IterationDefinition(Guid Id, Guid? ParentIterationId, int Number, string Name,
    IterationStatus Status, IReadOnlyList<ModelDefinition> Models, IReadOnlyList<PageDefinition> Pages,
    IReadOnlyList<FunctionGraph> Functions, IReadOnlyList<PluginDefinition> Plugins);

public sealed record ModelDefinition(Guid Id, string Name, string TableName, bool Timestamps, bool SoftDeletes,
    IReadOnlyList<FieldDefinition> Fields, IReadOnlyList<RelationshipDefinition> Relationships);
public sealed record FieldDefinition(Guid Id, string Name, string Label, string Type, bool Nullable, bool Indexed,
    bool Unique, object? DefaultValue, IReadOnlyList<string> ValidationRules, int Position);
public sealed record RelationshipDefinition(Guid Id, string Name, string Type, Guid TargetModelId,
    string? ForeignKey = null, string? PivotTable = null);
public sealed record PageDefinition(Guid Id, Guid? ModelId, string Name, string Slug, string Type, string Layout,
    IReadOnlyList<ControlDefinition> Controls, IReadOnlyList<EventBinding> EventBindings, int Position);
public sealed record ControlDefinition(Guid Id, Guid? FieldId, string Type, string Label, string Width, int Position,
    IReadOnlyDictionary<string, object?> Configuration);
public sealed record EventBinding(Guid? ControlId, string Event, Guid FunctionId);
public sealed record PluginDefinition(Guid Id, string Ecosystem, string Package, string Constraint, bool Approved);

public static class Slug
{
    public static string Create(string value)
    {
        var normalized = value.Trim().ToLowerInvariant().Normalize(NormalizationForm.FormD);
        var characters = normalized
            .Where(character => CharUnicodeInfo.GetUnicodeCategory(character) != UnicodeCategory.NonSpacingMark)
            .Select(character => character is >= 'a' and <= 'z' or >= '0' and <= '9' ? character : '-');
        var slug = string.Join('-', new string(characters.ToArray()).Split('-', StringSplitOptions.RemoveEmptyEntries));
        return string.IsNullOrWhiteSpace(slug) ? "project" : slug;
    }
}
