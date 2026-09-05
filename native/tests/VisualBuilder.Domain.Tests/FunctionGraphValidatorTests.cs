using VisualBuilder.Domain.Functions;

namespace VisualBuilder.Domain.Tests;

public sealed class FunctionGraphValidatorTests
{
    private readonly FunctionGraphValidator _validator = new();

    [Fact]
    public void Accepts_a_simple_event_to_return_graph()
    {
        var entry = Node(FunctionNodeKind.Event);
        var terminal = Node(FunctionNodeKind.Return);
        var graph = Graph([entry, terminal], [Edge(entry, terminal)]);

        Assert.Empty(_validator.Validate(graph));
    }

    [Fact]
    public void Requires_exactly_one_event_and_a_return_node()
    {
        var issues = _validator.Validate(Graph([Node(FunctionNodeKind.Notify)], []));

        Assert.Contains(issues, issue => issue.Code == "graph.event-count");
        Assert.Contains(issues, issue => issue.Code == "graph.return-required");
    }

    [Fact]
    public void Rejects_edges_to_unknown_nodes()
    {
        var entry = Node(FunctionNodeKind.Event);
        var terminal = Node(FunctionNodeKind.Return);
        var graph = Graph([entry, terminal], [new(Guid.NewGuid(), entry.Id, "next", Guid.NewGuid(), "input")]);

        Assert.Contains(_validator.Validate(graph), issue => issue.Code == "edge.dangling");
    }

    [Fact]
    public void Requires_authorization_and_validation_for_deletes()
    {
        var entry = Node(FunctionNodeKind.Event);
        var delete = Node(FunctionNodeKind.DeleteRecord);
        var terminal = Node(FunctionNodeKind.Return);
        var graph = Graph([entry, delete, terminal], [Edge(entry, delete), Edge(delete, terminal)]);

        var issues = _validator.Validate(graph);
        Assert.Contains(issues, issue => issue.Code == "delete.authorization-required");
        Assert.Contains(issues, issue => issue.Code == "delete.validation-required");
    }

    [Fact]
    public void Rejects_an_unbounded_cycle()
    {
        var entry = Node(FunctionNodeKind.Event);
        var notify = Node(FunctionNodeKind.Notify);
        var terminal = Node(FunctionNodeKind.Return);
        var graph = Graph([entry, notify, terminal], [Edge(entry, notify), Edge(notify, entry), Edge(notify, terminal)]);

        Assert.Contains(_validator.Validate(graph), issue => issue.Code == "graph.unbounded-cycle");
    }

    [Fact]
    public void Accepts_a_cycle_with_a_bounded_loop_node()
    {
        var entry = Node(FunctionNodeKind.Event);
        var loop = Node(FunctionNodeKind.Loop, new Dictionary<string, object?> { ["maxIterations"] = 10 });
        var work = Node(FunctionNodeKind.SetValue);
        var terminal = Node(FunctionNodeKind.Return);
        var graph = Graph([entry, loop, work, terminal], [Edge(entry, loop), Edge(loop, work), Edge(work, loop), Edge(loop, terminal)]);

        Assert.Empty(_validator.Validate(graph));
    }

    private static FunctionNode Node(FunctionNodeKind kind, IReadOnlyDictionary<string, object?>? configuration = null) =>
        new(Guid.NewGuid(), kind, 1, new(0, 0), configuration ?? new Dictionary<string, object?>());

    private static FunctionEdge Edge(FunctionNode source, FunctionNode target) => new(Guid.NewGuid(), source.Id, "next", target.Id, "input");

    private static FunctionGraph Graph(IReadOnlyList<FunctionNode> nodes, IReadOnlyList<FunctionEdge> edges) =>
        new(Guid.NewGuid(), "Test function", FunctionScope.Project, null, nodes, edges);
}
