namespace VisualBuilder.Domain.Functions;

public sealed record FunctionGraph(
    Guid Id,
    string Name,
    FunctionScope Scope,
    Guid? PageId,
    IReadOnlyList<FunctionNode> Nodes,
    IReadOnlyList<FunctionEdge> Edges);

public sealed record FunctionNode(
    Guid Id,
    FunctionNodeKind Kind,
    int Version,
    NodePosition Position,
    IReadOnlyDictionary<string, object?> Configuration);

public sealed record FunctionEdge(Guid Id, Guid SourceNodeId, string SourcePort, Guid TargetNodeId, string TargetPort);

public readonly record struct NodePosition(double X, double Y);
public enum FunctionScope { Page, Project }

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
