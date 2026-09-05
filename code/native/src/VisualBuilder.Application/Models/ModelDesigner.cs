using System.Text.RegularExpressions;
using VisualBuilder.Application.Projects;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.Application.Models;

public sealed partial class ModelDesigner(ProjectWorkspace workspace)
{
    public ModelDefinition AddModel(ModelInput input)
    {
        var document = RequireDocument();
        ValidateModel(input, document.Iterations[^1].Models);
        var model = new ModelDefinition(Guid.NewGuid(), input.Name.Trim(), input.TableName.Trim(), input.Timestamps,
            input.SoftDeletes, [], []);
        UpdateIteration(iteration => iteration with { Models = [.. iteration.Models, model] });
        return model;
    }

    public void UpdateModel(Guid modelId, ModelInput input)
    {
        var models = RequireDocument().Iterations[^1].Models;
        ValidateModel(input, models.Where(model => model.Id != modelId));
        UpdateModelCore(modelId, model => model with
        {
            Name = input.Name.Trim(), TableName = input.TableName.Trim(), Timestamps = input.Timestamps,
            SoftDeletes = input.SoftDeletes
        });
    }

    public void RemoveModel(Guid modelId)
    {
        var iteration = RequireDocument().Iterations[^1];
        if (iteration.Models.Any(model => model.Relationships.Any(relationship => relationship.TargetModelId == modelId)))
            throw new ModelDesignException("Remove relationships targeting this model before deleting it.");
        UpdateIteration(current => current with { Models = current.Models.Where(model => model.Id != modelId).ToArray() });
    }

    public FieldDefinition AddField(Guid modelId, FieldInput input)
    {
        var model = FindModel(modelId);
        ValidateField(input, model.Fields);
        var field = ToField(Guid.NewGuid(), input, model.Fields.Count);
        UpdateModelCore(modelId, current => current with { Fields = [.. current.Fields, field] });
        return field;
    }

    public void UpdateField(Guid modelId, Guid fieldId, FieldInput input)
    {
        var model = FindModel(modelId);
        ValidateField(input, model.Fields.Where(field => field.Id != fieldId));
        UpdateModelCore(modelId, current => current with
        {
            Fields = current.Fields.Select(field => field.Id == fieldId ? ToField(field.Id, input, field.Position) : field).ToArray()
        });
    }

    public void RemoveField(Guid modelId, Guid fieldId) => UpdateModelCore(modelId, model => model with
    {
        Fields = model.Fields.Where(field => field.Id != fieldId)
            .Select((field, position) => field with { Position = position }).ToArray()
    });

    public RelationshipDefinition AddRelationship(Guid modelId, RelationshipInput input)
    {
        var model = FindModel(modelId);
        if (!RequireDocument().Iterations[^1].Models.Any(candidate => candidate.Id == input.TargetModelId))
            throw new ModelDesignException("Select a target model that exists in this iteration.");
        if (string.IsNullOrWhiteSpace(input.Name)) throw new ModelDesignException("A relationship name is required.");
        if (model.Relationships.Any(item => item.Name.Equals(input.Name.Trim(), StringComparison.OrdinalIgnoreCase)))
            throw new ModelDesignException("Relationship names must be unique within a model.");

        var relationship = new RelationshipDefinition(Guid.NewGuid(), input.Name.Trim(), input.Type,
            input.TargetModelId, EmptyToNull(input.ForeignKey), EmptyToNull(input.PivotTable));
        UpdateModelCore(modelId, current => current with { Relationships = [.. current.Relationships, relationship] });
        return relationship;
    }

    public void RemoveRelationship(Guid modelId, Guid relationshipId) => UpdateModelCore(modelId, model => model with
    {
        Relationships = model.Relationships.Where(item => item.Id != relationshipId).ToArray()
    });

    public IReadOnlyList<IncomingModelReference> GetIncomingReferences(Guid targetModelId) => RequireDocument().Iterations[^1].Models
        .SelectMany(source => source.Relationships
            .Where(relationship => relationship.TargetModelId == targetModelId)
            .Select(relationship => new IncomingModelReference(source.Id, source.Name, relationship.Id,
                relationship.Name, relationship.Type)))
        .ToArray();

    private ProjectDocument RequireDocument() => workspace.Current ?? throw new InvalidOperationException("Open a project first.");
    private ModelDefinition FindModel(Guid id) => RequireDocument().Iterations[^1].Models.FirstOrDefault(model => model.Id == id)
        ?? throw new ModelDesignException("The selected model no longer exists.");

    private void UpdateModelCore(Guid id, Func<ModelDefinition, ModelDefinition> update) =>
        UpdateIteration(iteration => iteration with { Models = iteration.Models.Select(model => model.Id == id ? update(model) : model).ToArray() });

    private void UpdateIteration(Func<IterationDefinition, IterationDefinition> update) => workspace.Update(document =>
    {
        var iteration = update(document.Iterations[^1]);
        return document with { Iterations = document.Iterations.Take(document.Iterations.Count - 1).Append(iteration).ToArray() };
    });

    private static void ValidateModel(ModelInput input, IEnumerable<ModelDefinition> existing)
    {
        if (!PascalCaseName().IsMatch(input.Name.Trim()))
            throw new ModelDesignException("Model names must use PascalCase, for example CustomerOrder.");
        if (!SnakeCaseName().IsMatch(input.TableName.Trim()))
            throw new ModelDesignException("Table names must use snake_case, for example customer_orders.");
        if (existing.Any(model => model.Name.Equals(input.Name.Trim(), StringComparison.OrdinalIgnoreCase)))
            throw new ModelDesignException("Model names must be unique.");
        if (existing.Any(model => model.TableName.Equals(input.TableName.Trim(), StringComparison.OrdinalIgnoreCase)))
            throw new ModelDesignException("Table names must be unique.");
    }

    private static void ValidateField(FieldInput input, IEnumerable<FieldDefinition> existing)
    {
        var name = input.Name.Trim();
        if (!SnakeCaseName().IsMatch(name)) throw new ModelDesignException("Field names must use snake_case.");
        if (ReservedFields.Contains(name)) throw new ModelDesignException($"'{name}' is managed automatically and cannot be added as a field.");
        if (string.IsNullOrWhiteSpace(input.Label)) throw new ModelDesignException("A field label is required.");
        if (!SupportedFieldTypes.Contains(input.Type)) throw new ModelDesignException("Select a supported field type.");
        if (existing.Any(field => field.Name.Equals(name, StringComparison.OrdinalIgnoreCase)))
            throw new ModelDesignException("Field names must be unique within a model.");
    }

    private static FieldDefinition ToField(Guid id, FieldInput input, int position) => new(id, input.Name.Trim(),
        input.Label.Trim(), input.Type, input.Nullable, input.Indexed || input.Unique, input.Unique,
        EmptyToNull(input.DefaultValue), input.ValidationRules.Where(rule => !string.IsNullOrWhiteSpace(rule)).Select(rule => rule.Trim()).ToArray(), position);

    private static string? EmptyToNull(string? value) => string.IsNullOrWhiteSpace(value) ? null : value.Trim();
    public static string SuggestedTableName(string modelName) => ToSnakeCase(modelName) + "s";
    private static string ToSnakeCase(string value) => Regex.Replace(value.Trim(), "(?<!^)([A-Z])", "_$1").ToLowerInvariant();

    private static readonly HashSet<string> ReservedFields = ["id", "created_at", "updated_at", "deleted_at"];
    public static readonly string[] SupportedFieldTypes = ["string", "text", "integer", "decimal", "boolean", "date", "datetime", "json"];
    public static readonly string[] SupportedRelationshipTypes = ["belongs-to", "has-one", "has-many", "belongs-to-many"];

    [GeneratedRegex("^[A-Z][A-Za-z0-9]*$")]
    private static partial Regex PascalCaseName();
    [GeneratedRegex("^[a-z][a-z0-9_]*$")]
    private static partial Regex SnakeCaseName();
}

public sealed record ModelInput(string Name, string TableName, bool Timestamps, bool SoftDeletes);
public sealed record FieldInput(string Name, string Label, string Type, bool Nullable, bool Indexed, bool Unique,
    string? DefaultValue, IReadOnlyList<string> ValidationRules);
public sealed record RelationshipInput(string Name, string Type, Guid TargetModelId, string? ForeignKey, string? PivotTable);
public sealed record IncomingModelReference(Guid SourceModelId, string SourceModelName, Guid RelationshipId,
    string RelationshipName, string RelationshipType);
public sealed class ModelDesignException(string message) : Exception(message);
