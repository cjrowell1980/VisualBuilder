namespace VisualBuilder.Domain.Functions;

public sealed class FunctionGraphValidator
{
    public IReadOnlyList<ValidationIssue> Validate(FunctionGraph graph)
    {
        var issues = new List<ValidationIssue>();
        var nodeIds = graph.Nodes.Select(node => node.Id).ToHashSet();

        AddDuplicateIssues(graph.Nodes.Select(node => node.Id), "node", issues);
        AddDuplicateIssues(graph.Edges.Select(edge => edge.Id), "edge", issues);

        if (graph.Nodes.Count(node => node.Kind == FunctionNodeKind.Event) != 1)
            issues.Add(new("graph.event-count", "A function must contain exactly one event entry node."));

        if (!graph.Nodes.Any(node => node.Kind == FunctionNodeKind.Return))
            issues.Add(new("graph.return-required", "A function must contain at least one return node."));

        foreach (var edge in graph.Edges)
        {
            if (!nodeIds.Contains(edge.SourceNodeId) || !nodeIds.Contains(edge.TargetNodeId))
                issues.Add(new("edge.dangling", $"Edge '{edge.Id}' references a node that does not exist.", edge.Id));
            if (edge.SourceNodeId == edge.TargetNodeId)
                issues.Add(new("edge.self-reference", "A node cannot connect directly to itself.", edge.Id));
        }

        if (graph.Nodes.Any(node => node.Kind is FunctionNodeKind.CreateRecord or FunctionNodeKind.UpdateRecord or FunctionNodeKind.DeleteRecord) &&
            !graph.Nodes.Any(node => node.Kind == FunctionNodeKind.Validate))
            issues.Add(new("write.validation-required", "Database write functions must include a validation node."));

        if (graph.Nodes.Any(node => node.Kind == FunctionNodeKind.DeleteRecord))
        {
            if (!graph.Nodes.Any(node => node.Kind == FunctionNodeKind.Authorize))
                issues.Add(new("delete.authorization-required", "Delete functions must include an authorization node."));
        }

        ValidateCycles(graph, nodeIds, issues);
        return issues;
    }

    private static void AddDuplicateIssues(IEnumerable<Guid> ids, string kind, ICollection<ValidationIssue> issues)
    {
        foreach (var id in ids.GroupBy(id => id).Where(group => group.Count() > 1).Select(group => group.Key))
            issues.Add(new($"{kind}.duplicate-id", $"The {kind} id '{id}' is used more than once.", id));
    }

    private static void ValidateCycles(FunctionGraph graph, HashSet<Guid> nodeIds, ICollection<ValidationIssue> issues)
    {
        var nodes = graph.Nodes.ToDictionary(node => node.Id);
        var adjacency = graph.Edges
            .Where(edge => nodeIds.Contains(edge.SourceNodeId) && nodeIds.Contains(edge.TargetNodeId))
            .GroupBy(edge => edge.SourceNodeId)
            .ToDictionary(group => group.Key, group => group.Select(edge => edge.TargetNodeId).ToArray());
        var visiting = new HashSet<Guid>();
        var visited = new HashSet<Guid>();
        var path = new List<Guid>();

        foreach (var node in graph.Nodes)
            Visit(node.Id);

        void Visit(Guid nodeId)
        {
            if (visited.Contains(nodeId)) return;
            if (visiting.Contains(nodeId))
            {
                var cycleStart = path.IndexOf(nodeId);
                var cycle = path.Skip(cycleStart).Select(id => nodes[id]).ToArray();
                var bounded = cycle.Any(node => node.Kind == FunctionNodeKind.Loop && HasPositiveIterationLimit(node));
                if (!bounded && !issues.Any(issue => issue.Code == "graph.unbounded-cycle"))
                    issues.Add(new("graph.unbounded-cycle", "Cycles are only allowed through a loop node with a positive maxIterations value."));
                return;
            }

            visiting.Add(nodeId);
            path.Add(nodeId);
            if (adjacency.TryGetValue(nodeId, out var targets))
                foreach (var target in targets) Visit(target);
            path.RemoveAt(path.Count - 1);
            visiting.Remove(nodeId);
            visited.Add(nodeId);
        }
    }

    private static bool HasPositiveIterationLimit(FunctionNode node)
    {
        if (!node.Configuration.TryGetValue("maxIterations", out var value) || value is null) return false;
        return value switch
        {
            int number => number > 0,
            long number => number > 0,
            string text => int.TryParse(text, out var number) && number > 0,
            _ => false
        };
    }
}

public sealed record ValidationIssue(string Code, string Message, Guid? SubjectId = null);
