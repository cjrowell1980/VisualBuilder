using System.Text.RegularExpressions;
using VisualBuilder.Application.Projects;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.Application.Models;

public sealed partial class ModelDesigner(ProjectWorkspace workspace)
{
    public ModelDefinition AddModel(ModelInput input)
    {
        input = Normalize(input);
        var document = RequireDocument();
        ValidateModel(input, document.Iterations[^1].Models);
        var model = new ModelDefinition(Guid.NewGuid(), input.Name.Trim(), input.TableName.Trim(), input.Timestamps,
            input.SoftDeletes, [], []);
        UpdateIteration(iteration => iteration with { Models = [.. iteration.Models, model] });
        return model;
    }

    public void UpdateModel(Guid modelId, ModelInput input)
    {
        input = Normalize(input);
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
        if (iteration.Pages.Any(page => page.ModelId == modelId))
            throw new ModelDesignException("Remove or rebind pages using this model before deleting it.");
        UpdateIteration(current => current with { Models = current.Models.Where(model => model.Id != modelId).ToArray() });
    }

    public FieldDefinition AddField(Guid modelId, FieldInput input)
    {
        var model = FindModel(modelId);
        input = Normalize(input, model);
        ValidateField(input, model.Fields);
        var field = ToField(Guid.NewGuid(), input, model.Fields.Count);
        UpdateModelCore(modelId, current => current with { Fields = [.. current.Fields, field] });
        return field;
    }

    public void UpdateField(Guid modelId, Guid fieldId, FieldInput input)
    {
        var model = FindModel(modelId);
        input = Normalize(input, model);
        ValidateField(input, model.Fields.Where(field => field.Id != fieldId));
        UpdateModelCore(modelId, current => current with
        {
            Fields = current.Fields.Select(field => field.Id == fieldId ? ToField(field.Id, input, field.Position) : field).ToArray()
        });
    }

    public void RemoveField(Guid modelId, Guid fieldId)
    {
        if (RequireDocument().Iterations[^1].Pages.Any(page => page.Controls.Any(control => control.FieldId == fieldId)))
            throw new ModelDesignException("Remove controls bound to this field before deleting it.");
        UpdateModelCore(modelId, model => model with
        {
            Fields = model.Fields.Where(field => field.Id != fieldId)
                .Select((field, position) => field with { Position = position }).ToArray()
        });
    }

    public RelationshipDefinition AddRelationship(Guid modelId, RelationshipInput input)
    {
        var model = FindModel(modelId);
        var target = RequireDocument().Iterations[^1].Models.FirstOrDefault(candidate => candidate.Id == input.TargetModelId);
        if (target is null)
            throw new ModelDesignException("Select a target model that exists in this iteration.");
        input = Normalize(input, model, target);
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

    public void UpdateRelationship(Guid modelId, Guid relationshipId, RelationshipInput input)
    {
        var model = FindModel(modelId);
        var target = RequireDocument().Iterations[^1].Models.FirstOrDefault(candidate => candidate.Id == input.TargetModelId)
            ?? throw new ModelDesignException("Select a target model that exists in this iteration.");
        input = Normalize(input, model, target);
        if (model.Relationships.Any(item => item.Id != relationshipId &&
            item.Name.Equals(input.Name.Trim(), StringComparison.OrdinalIgnoreCase)))
            throw new ModelDesignException("Relationship names must be unique within a model.");
        UpdateModelCore(modelId, current => current with
        {
            Relationships = current.Relationships.Select(item => item.Id == relationshipId
                ? item with { Name = input.Name.Trim(), Type = input.Type, TargetModelId = input.TargetModelId,
                    ForeignKey = EmptyToNull(input.ForeignKey), PivotTable = EmptyToNull(input.PivotTable) }
                : item).ToArray()
        });
    }

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

    private static ModelInput Normalize(ModelInput input)
    {
        var name = string.IsNullOrWhiteSpace(input.Name) ? "CustomerOrder" : input.Name.Trim();
        var table = string.IsNullOrWhiteSpace(input.TableName) ? SuggestedTableName(name) : input.TableName.Trim();
        return input with { Name = name, TableName = table };
    }

    private static FieldInput Normalize(FieldInput input, ModelDefinition model)
    {
        var suggestion = SuggestedField(model);
        var name = string.IsNullOrWhiteSpace(input.Name) ? suggestion.Name : input.Name.Trim();
        var label = string.IsNullOrWhiteSpace(input.Label) ? suggestion.Label : input.Label.Trim();
        return input with { Name = name, Label = label, Type = string.IsNullOrWhiteSpace(input.Type) ? "string" : input.Type };
    }

    public static FieldSuggestion SuggestedField(ModelDefinition model)
    {
        var candidates = model.Name.Contains("Order", StringComparison.OrdinalIgnoreCase)
            ? new[] { "number", "order_date", "total", "status", "notes" }
            : model.Name.Contains("Product", StringComparison.OrdinalIgnoreCase)
                ? new[] { "name", "sku", "description", "price", "is_active" }
                : model.Name.Contains("Company", StringComparison.OrdinalIgnoreCase)
                    ? new[] { "name", "company_no", "vat", "email", "phone" }
                    : new[] { "name", "email", "phone", "company_no", "vat", "description" };
        var name = candidates.FirstOrDefault(candidate => model.Fields.All(field => !field.Name.Equals(candidate, StringComparison.OrdinalIgnoreCase)))
            ?? $"field_{model.Fields.Count + 1}";
        return new(name, string.Join(' ', name.Split('_')).ToLowerInvariant() is var label
            ? char.ToUpperInvariant(label[0]) + label[1..] : name);
    }

    private static RelationshipInput Normalize(RelationshipInput input, ModelDefinition source, ModelDefinition target)
    {
        var targetName = ToSnakeCase(target.Name);
        var name = string.IsNullOrWhiteSpace(input.Name) ? targetName : input.Name.Trim();
        var foreignKey = string.IsNullOrWhiteSpace(input.ForeignKey) && input.Type == "belongs-to" ? targetName + "_id" : input.ForeignKey;
        var pivot = string.IsNullOrWhiteSpace(input.PivotTable) && input.Type == "belongs-to-many"
            ? string.Join('_', new[] { Singular(source.TableName), Singular(target.TableName) }.Order())
            : input.PivotTable;
        return input with { Name = name, ForeignKey = foreignKey, PivotTable = pivot };
    }

    private static string Singular(string table) => table.EndsWith('s') ? table[..^1] : table;

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
public sealed record FieldSuggestion(string Name, string Label);
public sealed class ModelDesignException(string message) : Exception(message);
