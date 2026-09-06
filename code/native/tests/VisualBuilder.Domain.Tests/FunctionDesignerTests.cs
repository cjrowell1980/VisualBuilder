using VisualBuilder.Application.Functions;
using VisualBuilder.Application.Pages;
using VisualBuilder.Application.Projects;
using VisualBuilder.Domain.Functions;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.Domain.Tests;

public sealed class FunctionDesignerTests
{
    [Fact]
    public async Task Creates_a_safe_graph_and_inserts_and_removes_blocks_in_sequence()
    {
        var workspace = await Workspace();
        var designer = new FunctionDesigner(workspace, new FunctionGraphValidator());
        var graph = designer.AddFunction(new("Save customer", FunctionScope.Project, null, "submitted"));
        var validate = designer.AddBlock(graph.Id, FunctionNodeKind.Validate, new Dictionary<string, object?> { ["rules"] = "email" });
        designer.AddBlock(graph.Id, FunctionNodeKind.Notify, new Dictionary<string, object?> { ["message"] = "Saved" });

        var current = workspace.Current!.Iterations[0].Functions.Single();
        Assert.Equal(4, current.Nodes.Count);
        Assert.Equal(3, current.Edges.Count);
        Assert.Empty(designer.Validate(graph.Id));

        designer.RemoveBlock(graph.Id, validate.Id);
        current = workspace.Current.Iterations[0].Functions.Single();
        Assert.Equal(3, current.Nodes.Count);
        Assert.Equal(2, current.Edges.Count);
    }

    [Fact]
    public async Task Binds_page_events_and_protects_bound_functions()
    {
        var workspace = await Workspace();
        var page = new PageDesigner(workspace).AddPage(new("Customers", "customers", "index", "app", null));
        var designer = new FunctionDesigner(workspace, new FunctionGraphValidator());
        var graph = designer.AddFunction(new("Load customers", FunctionScope.Page, page.Id, "page-loaded"));

        designer.BindEvent(page.Id, null, "page-loaded", graph.Id);
        Assert.Equal(graph.Id, workspace.Current!.Iterations[0].Pages.Single().EventBindings.Single().FunctionId);
        Assert.Throws<FunctionDesignException>(() => designer.RemoveFunction(graph.Id));

        designer.RemoveBinding(page.Id, null, "page-loaded");
        designer.RemoveFunction(graph.Id);
        Assert.Empty(workspace.Current.Iterations[0].Functions);
    }

    [Fact]
    public async Task Rejects_page_function_without_a_valid_page()
    {
        var workspace = await Workspace();
        var designer = new FunctionDesigner(workspace, new FunctionGraphValidator());
        Assert.Throws<FunctionDesignException>(() => designer.AddFunction(new("Invalid", FunctionScope.Page, Guid.NewGuid(), "clicked")));
    }

    private static async Task<ProjectWorkspace> Workspace()
    {
        var workspace = new ProjectWorkspace(new MemoryDocuments(), new MemoryRecent());
        await workspace.CreateAsync(new("Test", ApplicationType.Web, StarterKit.Livewire, DatabaseEngine.Sqlite, false), "test.vbproject");
        return workspace;
    }

    private sealed class MemoryDocuments : IProjectDocumentStore
    {
        public Task<ProjectDocument> LoadAsync(string path, CancellationToken cancellationToken = default) => throw new NotSupportedException();
        public Task SaveAsync(string path, ProjectDocument document, CancellationToken cancellationToken = default) => Task.CompletedTask;
    }
    private sealed class MemoryRecent : IRecentProjectsStore
    {
        public Task<IReadOnlyList<RecentProject>> LoadAsync(CancellationToken cancellationToken = default) => Task.FromResult<IReadOnlyList<RecentProject>>([]);
        public Task RecordAsync(RecentProject project, CancellationToken cancellationToken = default) => Task.CompletedTask;
    }
}
