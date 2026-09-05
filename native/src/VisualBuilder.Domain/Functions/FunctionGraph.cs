namespace VisualBuilder.Domain.Functions;

public sealed record FunctionGraph(
    Guid Id,
    string Name,
    IReadOnlyList<FunctionNode> Nodes,
    IReadOnlyList<FunctionEdge> Edges);

public sealed record FunctionNode(
    Guid Id,
    FunctionNodeKind Kind,
    string Label,
    NodePosition Position,
    IReadOnlyDictionary<string, object?> Configuration);

public sealed record FunctionEdge(Guid Id, Guid SourceNodeId, Guid TargetNodeId, string? Branch = null);

public readonly record struct NodePosition(double X, double Y);

public enum FunctionNodeKind
{
    Event,
    Validate,
    Authorize,
    Query,
    CreateRecord,
    UpdateRecord,
    DeleteRecord,
    Condition,
    Loop,
    SetValue,
    Notify,
    Navigate,
    OpenModal,
    CloseModal,
    DispatchJob,
    SendEmail,
    HttpRequest,
    CallFunction,
    Return,
    CustomCode
}
