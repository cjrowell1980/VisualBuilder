using System.Text.RegularExpressions;
using VisualBuilder.Application.Projects;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.Application.Pages;

public sealed partial class PageDesigner(ProjectWorkspace workspace)
{
    public PageDefinition AddPage(PageInput input)
    {
        input = Normalize(input);
        var iteration = CurrentIteration();
        ValidatePage(input, iteration.Pages, iteration.Models);
        var page = new PageDefinition(Guid.NewGuid(), input.ModelId, input.Name, input.Slug, input.Type,
            input.Layout, [], [], iteration.Pages.Count, EmptyToNull(input.Category), input.ParentPageId);
        UpdateIteration(current => current with { Pages = [.. current.Pages, page] });
        return page;
    }

    public void UpdatePage(Guid pageId, PageInput input)
    {
        input = Normalize(input);
        var iteration = CurrentIteration();
        ValidatePage(input, iteration.Pages.Where(page => page.Id != pageId), iteration.Models);
        UpdatePageCore(pageId, page => page with
        {
            Name = input.Name, Slug = input.Slug, Type = input.Type, Layout = input.Layout, ModelId = input.ModelId,
            Category = EmptyToNull(input.Category), ParentPageId = input.ParentPageId
        });
    }

    public void RemovePage(Guid pageId)
    {
        if (CurrentIteration().Pages.Any(page => page.ParentPageId == pageId))
            throw new PageDesignException("Move or remove child pages before deleting their parent page.");
        UpdateIteration(iteration => iteration with
        {
            Pages = iteration.Pages.Where(page => page.Id != pageId)
                .Select((page, position) => page with { Position = position }).ToArray()
        });
    }

    public ControlDefinition AddControl(Guid pageId, ControlInput input)
    {
        var page = FindPage(pageId);
        input = Normalize(input);
        ValidateControl(input, page);
        var control = new ControlDefinition(Guid.NewGuid(), input.FieldId, input.Type, input.Label,
            input.Width, page.Controls.Count, input.Configuration);
        UpdatePageCore(pageId, current => current with { Controls = [.. current.Controls, control] });
        return control;
    }

    public void UpdateControl(Guid pageId, Guid controlId, ControlInput input)
    {
        var page = FindPage(pageId);
        input = Normalize(input);
        ValidateControl(input, page);
        UpdatePageCore(pageId, current => current with
        {
            Controls = current.Controls.Select(control => control.Id == controlId ? control with
            {
                FieldId = input.FieldId, Type = input.Type, Label = input.Label,
                Width = input.Width, Configuration = input.Configuration
            } : control).ToArray()
        });
    }

    public void RemoveControl(Guid pageId, Guid controlId) => UpdatePageCore(pageId, page => page with
    {
        Controls = page.Controls.Where(control => control.Id != controlId)
            .Select((control, position) => control with { Position = position }).ToArray()
    });

    public void MoveControl(Guid pageId, Guid controlId, int offset)
    {
        var page = FindPage(pageId);
        var controls = page.Controls.OrderBy(control => control.Position).ToList();
        var currentIndex = controls.FindIndex(control => control.Id == controlId);
        if (currentIndex < 0) return;
        var targetIndex = Math.Clamp(currentIndex + offset, 0, controls.Count - 1);
        if (currentIndex == targetIndex) return;
        var moving = controls[currentIndex];
        controls.RemoveAt(currentIndex);
        controls.Insert(targetIndex, moving);
        UpdatePageCore(pageId, current => current with
        {
            Controls = controls.Select((control, position) => control with { Position = position }).ToArray()
        });
    }

    private IterationDefinition CurrentIteration() => workspace.Current?.Iterations[^1]
        ?? throw new InvalidOperationException("Open a project first.");
    private PageDefinition FindPage(Guid pageId) => CurrentIteration().Pages.FirstOrDefault(page => page.Id == pageId)
        ?? throw new PageDesignException("The selected page no longer exists.");

    private void UpdatePageCore(Guid pageId, Func<PageDefinition, PageDefinition> update) =>
        UpdateIteration(iteration => iteration with { Pages = iteration.Pages.Select(page => page.Id == pageId ? update(page) : page).ToArray() });

    private void UpdateIteration(Func<IterationDefinition, IterationDefinition> update) => workspace.Update(document =>
    {
        var iteration = update(document.Iterations[^1]);
        return document with { Iterations = document.Iterations.Take(document.Iterations.Count - 1).Append(iteration).ToArray() };
    });

    private static PageInput Normalize(PageInput input)
    {
        var name = string.IsNullOrWhiteSpace(input.Name) ? "Customer list" : input.Name.Trim();
        var slug = string.IsNullOrWhiteSpace(input.Slug) ? Slug.Create(name) : input.Slug.Trim();
        return input with { Name = name, Slug = slug, Type = string.IsNullOrWhiteSpace(input.Type) ? "index" : input.Type,
            Layout = string.IsNullOrWhiteSpace(input.Layout) ? "app" : input.Layout };
    }

    private static ControlInput Normalize(ControlInput input) => input with
    {
        Type = string.IsNullOrWhiteSpace(input.Type) ? "input" : input.Type,
        Label = string.IsNullOrWhiteSpace(input.Label) ? "Company name" : input.Label.Trim(),
        Width = string.IsNullOrWhiteSpace(input.Width) ? "full" : input.Width
    };

    private static void ValidatePage(PageInput input, IEnumerable<PageDefinition> existing, IReadOnlyList<ModelDefinition> models)
    {
        if (input.Name.Length > 120) throw new PageDesignException("Page names cannot exceed 120 characters.");
        if (!SlugPattern().IsMatch(input.Slug)) throw new PageDesignException("Page slugs must contain lowercase letters, numbers and hyphens only.");
        if (!SupportedPageTypes.Contains(input.Type)) throw new PageDesignException("Select a supported page type.");
        if (!SupportedLayouts.Contains(input.Layout)) throw new PageDesignException("Select a supported page layout.");
        if (input.ModelId is not null && !models.Any(model => model.Id == input.ModelId))
            throw new PageDesignException("Select a model that exists in this iteration.");
        if (input.ParentPageId is not null && !existing.Any(page => page.Id == input.ParentPageId))
            throw new PageDesignException("Select a parent page that exists in this iteration.");
        if (existing.Any(page => page.Slug.Equals(input.Slug, StringComparison.OrdinalIgnoreCase)))
            throw new PageDesignException("Page slugs must be unique.");
    }

    private void ValidateControl(ControlInput input, PageDefinition page)
    {
        if (!SupportedControlTypes.Contains(input.Type)) throw new PageDesignException("Select a supported control type.");
        if (!SupportedWidths.Contains(input.Width)) throw new PageDesignException("Select a supported control width.");
        if (input.FieldId is null) return;
        if (page.ModelId is null) throw new PageDesignException("Bind the page to a model before selecting a field.");
        var model = CurrentIteration().Models.First(model => model.Id == page.ModelId);
        if (!model.Fields.Any(field => field.Id == input.FieldId))
            throw new PageDesignException("The selected field does not belong to the page model.");
    }

    public static readonly string[] SupportedPageTypes = ["dashboard", "index", "create", "edit", "show", "custom"];
    public static readonly string[] SupportedLayouts = ["app", "full-width", "guest"];
    public static readonly string[] SupportedControlTypes = ["heading", "text", "input", "textarea", "select", "checkbox", "date", "button", "table", "card"];
    public static readonly string[] SupportedWidths = ["full", "half", "third", "quarter"];
    private static string? EmptyToNull(string? value) => string.IsNullOrWhiteSpace(value) ? null : value.Trim();

    [GeneratedRegex("^[a-z0-9]+(?:-[a-z0-9]+)*$")]
    private static partial Regex SlugPattern();
}

public sealed record PageInput(string Name, string Slug, string Type, string Layout, Guid? ModelId,
    string? Category = null, Guid? ParentPageId = null);
public sealed record ControlInput(string Type, string Label, string Width, Guid? FieldId,
    IReadOnlyDictionary<string, object?> Configuration);
public sealed class PageDesignException(string message) : Exception(message);
