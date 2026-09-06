using VisualBuilder.Application.Projects;
using VisualBuilder.Domain.Functions;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.Application.Functions;

public sealed class FunctionDesigner(ProjectWorkspace workspace, FunctionGraphValidator validator)
{
    public FunctionGraph AddFunction(FunctionInput input)
    {
        var iteration = CurrentIteration();
        input = Normalize(input);
        Validate(input, iteration.Functions, iteration.Pages);
        var entry = new FunctionNode(Guid.NewGuid(), FunctionNodeKind.Event, 1, new(40, 40),
            new Dictionary<string, object?> { ["event"] = input.Event });
        var terminal = new FunctionNode(Guid.NewGuid(), FunctionNodeKind.Return, 1, new(40, 200),
            new Dictionary<string, object?>());
        var graph = new FunctionGraph(Guid.NewGuid(), input.Name, input.Scope, input.PageId, [entry, terminal],
            [new FunctionEdge(Guid.NewGuid(), entry.Id, "next", terminal.Id, "input")]);
        UpdateIteration(current => current with { Functions = [.. current.Functions, graph] });
        return graph;
    }

    public void UpdateFunction(Guid functionId, FunctionInput input)
    {
        input = Normalize(input);
        var iteration = CurrentIteration();
        Validate(input, iteration.Functions.Where(item => item.Id != functionId), iteration.Pages);
        UpdateGraph(functionId, graph => graph with
        {
            Name = input.Name, Scope = input.Scope, PageId = input.PageId,
            Nodes = graph.Nodes.Select(node => node.Kind == FunctionNodeKind.Event
                ? node with { Configuration = new Dictionary<string, object?>(node.Configuration) { ["event"] = input.Event } }
                : node).ToArray()
        });
    }

    public void RemoveFunction(Guid functionId)
    {
        if (CurrentIteration().Pages.Any(page => page.EventBindings.Any(binding => binding.FunctionId == functionId)))
            throw new FunctionDesignException("Remove page and control event bindings before deleting this function.");
        UpdateIteration(iteration => iteration with { Functions = iteration.Functions.Where(item => item.Id != functionId).ToArray() });
    }

    public FunctionNode AddBlock(Guid functionId, FunctionNodeKind kind, IReadOnlyDictionary<string, object?> configuration)
    {
        if (kind is FunctionNodeKind.Event or FunctionNodeKind.Return)
            throw new FunctionDesignException("Event and Return blocks are managed by VisualBuilder.");
        var graph = FindFunction(functionId);
        var terminal = graph.Nodes.Single(node => node.Kind == FunctionNodeKind.Return);
        var incoming = graph.Edges.FirstOrDefault(edge => edge.TargetNodeId == terminal.Id);
        var node = new FunctionNode(Guid.NewGuid(), kind, 1, new(40, 40 + graph.Nodes.Count * 80), configuration);
        var edges = graph.Edges.Where(edge => edge.Id != incoming?.Id).ToList();
        if (incoming is not null) edges.Add(new(Guid.NewGuid(), incoming.SourceNodeId, incoming.SourcePort, node.Id, "input"));
        edges.Add(new(Guid.NewGuid(), node.Id, "next", terminal.Id, "input"));
        UpdateGraph(functionId, current => current with { Nodes = [.. current.Nodes, node], Edges = edges });
        return node;
    }

    public void UpdateBlock(Guid functionId, Guid nodeId, IReadOnlyDictionary<string, object?> configuration) =>
        UpdateGraph(functionId, graph => graph with
        {
            Nodes = graph.Nodes.Select(node => node.Id == nodeId ? node with { Configuration = configuration } : node).ToArray()
        });

    public void RemoveBlock(Guid functionId, Guid nodeId)
    {
        var graph = FindFunction(functionId);
        var node = graph.Nodes.FirstOrDefault(item => item.Id == nodeId) ?? throw new FunctionDesignException("The selected block no longer exists.");
        if (node.Kind is FunctionNodeKind.Event or FunctionNodeKind.Return)
            throw new FunctionDesignException("Event and Return blocks cannot be removed.");
        var incoming = graph.Edges.Where(edge => edge.TargetNodeId == nodeId).ToArray();
        var outgoing = graph.Edges.Where(edge => edge.SourceNodeId == nodeId).ToArray();
        var edges = graph.Edges.Where(edge => edge.SourceNodeId != nodeId && edge.TargetNodeId != nodeId).ToList();
        if (incoming.Length == 1 && outgoing.Length == 1)
            edges.Add(new(Guid.NewGuid(), incoming[0].SourceNodeId, incoming[0].SourcePort, outgoing[0].TargetNodeId, outgoing[0].TargetPort));
        UpdateGraph(functionId, current => current with { Nodes = current.Nodes.Where(item => item.Id != nodeId).ToArray(), Edges = edges });
    }

    public void BindEvent(Guid pageId, Guid? controlId, string eventName, Guid functionId)
    {
        if (!SupportedEvents.Contains(eventName)) throw new FunctionDesignException("Select a supported page or control event.");
        var page = CurrentIteration().Pages.FirstOrDefault(item => item.Id == pageId)
            ?? throw new FunctionDesignException("Select a page that exists in this iteration.");
        var graph = FindFunction(functionId);
        if (graph.Scope == FunctionScope.Page && graph.PageId != pageId)
            throw new FunctionDesignException("A page function can only be bound to its own page.");
        if (controlId is not null && page.Controls.All(control => control.Id != controlId))
            throw new FunctionDesignException("The selected control does not belong to this page.");
        var binding = new EventBinding(controlId, eventName.Trim(), functionId);
        UpdatePage(pageId, current => current with
        {
            EventBindings = [.. current.EventBindings.Where(item => item.ControlId != controlId || !item.Event.Equals(eventName, StringComparison.OrdinalIgnoreCase)), binding]
        });
    }

    public void RemoveBinding(Guid pageId, Guid? controlId, string eventName) => UpdatePage(pageId, page => page with
    {
        EventBindings = page.EventBindings.Where(item => item.ControlId != controlId || !item.Event.Equals(eventName, StringComparison.OrdinalIgnoreCase)).ToArray()
    });

    public IReadOnlyList<ValidationIssue> Validate(Guid functionId) => validator.Validate(FindFunction(functionId));

    private IterationDefinition CurrentIteration() => workspace.Current?.Iterations[^1] ?? throw new InvalidOperationException("Open a project first.");
    private FunctionGraph FindFunction(Guid id) => CurrentIteration().Functions.FirstOrDefault(item => item.Id == id)
        ?? throw new FunctionDesignException("The selected function no longer exists.");
    private void UpdateGraph(Guid id, Func<FunctionGraph, FunctionGraph> update) => UpdateIteration(iteration => iteration with
    { Functions = iteration.Functions.Select(item => item.Id == id ? update(item) : item).ToArray() });
    private void UpdatePage(Guid id, Func<PageDefinition, PageDefinition> update) => UpdateIteration(iteration => iteration with
    { Pages = iteration.Pages.Select(item => item.Id == id ? update(item) : item).ToArray() });
    private void UpdateIteration(Func<IterationDefinition, IterationDefinition> update) => workspace.Update(document =>
    {
        var iteration = update(document.Iterations[^1]);
        return document with { Iterations = document.Iterations.Take(document.Iterations.Count - 1).Append(iteration).ToArray() };
    });
    private static FunctionInput Normalize(FunctionInput input) => input with
    {
        Name = string.IsNullOrWhiteSpace(input.Name) ? "New function" : input.Name.Trim(),
        Event = string.IsNullOrWhiteSpace(input.Event) ? "clicked" : input.Event.Trim()
    };
    private static void Validate(FunctionInput input, IEnumerable<FunctionGraph> existing, IReadOnlyList<PageDefinition> pages)
    {
        if (input.Name.Length > 120) throw new FunctionDesignException("Function names cannot exceed 120 characters.");
        if (existing.Any(item => item.Name.Equals(input.Name, StringComparison.OrdinalIgnoreCase)))
            throw new FunctionDesignException("Function names must be unique within an iteration.");
        if (input.Scope == FunctionScope.Page && (input.PageId is null || pages.All(page => page.Id != input.PageId)))
            throw new FunctionDesignException("Select a page for a page-scoped function.");
        if (input.Scope == FunctionScope.Project && input.PageId is not null)
            throw new FunctionDesignException("Project functions cannot be assigned to a page.");
        if (!SupportedEvents.Contains(input.Event)) throw new FunctionDesignException("Select a supported entry event.");
    }

    public static readonly string[] SupportedEvents = ["page-loaded", "submitted", "clicked", "changed"];
}

public sealed record FunctionInput(string Name, FunctionScope Scope, Guid? PageId, string Event);
public sealed class FunctionDesignException(string message) : Exception(message);
