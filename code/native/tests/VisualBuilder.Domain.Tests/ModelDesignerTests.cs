using VisualBuilder.Application.Models;
using VisualBuilder.Application.Projects;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.Domain.Tests;

public sealed class ModelDesignerTests
{
    [Fact]
    public async Task Adds_updates_and_removes_a_model_and_its_fields()
    {
        var workspace = await Workspace();
        var designer = new ModelDesigner(workspace);
        var model = designer.AddModel(new("Customer", "customers", true, true));
        var field = designer.AddField(model.Id, new("company_name", "Company name", "string", false, true, true, null, ["required", "max:120"]));

        designer.UpdateField(model.Id, field.Id, new("legal_name", "Legal name", "text", true, false, false, null, ["max:500"]));
        var updated = workspace.Current!.Iterations[0].Models.Single();
        Assert.Equal("legal_name", updated.Fields.Single().Name);
        Assert.False(updated.Fields.Single().Indexed);

        designer.RemoveField(model.Id, field.Id);
        designer.RemoveModel(model.Id);
        Assert.Empty(workspace.Current.Iterations[0].Models);
        Assert.True(workspace.IsDirty);
    }

    [Theory]
    [InlineData("customer", "Model names must use PascalCase")]
    [InlineData("Customer", "Model names must be unique")]
    public async Task Rejects_invalid_or_duplicate_models(string name, string message)
    {
        var workspace = await Workspace();
        var designer = new ModelDesigner(workspace);
        designer.AddModel(new("Customer", "customers", true, false));

        var exception = Assert.Throws<ModelDesignException>(() => designer.AddModel(new(name, name == "Customer" ? "other_customers" : "customers_2", true, false)));
        Assert.Contains(message, exception.Message);
    }

    [Theory]
    [InlineData("id", "managed automatically")]
    [InlineData("CompanyName", "snake_case")]
    public async Task Rejects_reserved_or_invalid_field_names(string name, string message)
    {
        var workspace = await Workspace();
        var designer = new ModelDesigner(workspace);
        var model = designer.AddModel(new("Customer", "customers", true, false));

        var exception = Assert.Throws<ModelDesignException>(() => designer.AddField(model.Id,
            new(name, "Field", "string", false, false, false, null, [])));
        Assert.Contains(message, exception.Message);
    }

    [Fact]
    public async Task Prevents_removing_a_model_targeted_by_a_relationship()
    {
        var workspace = await Workspace();
        var designer = new ModelDesigner(workspace);
        var customer = designer.AddModel(new("Customer", "customers", true, false));
        var order = designer.AddModel(new("Order", "orders", true, false));
        designer.AddRelationship(order.Id, new("customer", "belongs-to", customer.Id, "customer_id", null));

        var incoming = designer.GetIncomingReferences(customer.Id);
        Assert.Single(incoming);
        Assert.Equal("Order", incoming[0].SourceModelName);
        Assert.Equal("customer", incoming[0].RelationshipName);
        Assert.Throws<ModelDesignException>(() => designer.RemoveModel(customer.Id));
    }

    [Fact]
    public async Task Updates_relationship_details_and_model_aware_field_suggestions()
    {
        var workspace = await Workspace();
        var designer = new ModelDesigner(workspace);
        var order = designer.AddModel(new("Order", "orders", true, false));
        var customer = designer.AddModel(new("Customer", "customers", true, false));
        var field = designer.AddField(order.Id, new("", "", "", false, false, false, null, []));
        var relationship = designer.AddRelationship(order.Id, new("customer", "belongs-to", customer.Id, "customer_id", null));

        designer.UpdateRelationship(order.Id, relationship.Id, new("buyer", "has-one", customer.Id, "buyer_id", null));

        var updated = workspace.Current!.Iterations[0].Models.Single(model => model.Id == order.Id);
        Assert.Equal("number", field.Name);
        Assert.Equal("buyer", updated.Relationships.Single().Name);
        Assert.Equal("has-one", updated.Relationships.Single().Type);
    }

    [Fact]
    public async Task Persists_model_changes_in_the_project_document()
    {
        var documentStore = new InMemoryDocumentStore();
        var workspace = new ProjectWorkspace(documentStore, new InMemoryRecentStore());
        await workspace.CreateAsync(new("Orders", ApplicationType.Web, StarterKit.Livewire, DatabaseEngine.PostgreSql, true), "orders.vbproject");
        var designer = new ModelDesigner(workspace);
        designer.AddModel(new("Order", "orders", true, false));

        await workspace.SaveAsync();

        Assert.Single(documentStore.Document!.Iterations[0].Models);
        Assert.False(workspace.IsDirty);
    }

    [Fact]
    public async Task Closing_the_workspace_clears_the_document_and_dirty_state()
    {
        var workspace = await Workspace();
        new ModelDesigner(workspace).AddModel(new("Order", "orders", true, false));

        workspace.Close();

        Assert.Null(workspace.Current);
        Assert.Null(workspace.CurrentPath);
        Assert.False(workspace.IsDirty);
    }

    private static async Task<ProjectWorkspace> Workspace()
    {
        var workspace = new ProjectWorkspace(new InMemoryDocumentStore(), new InMemoryRecentStore());
        await workspace.CreateAsync(new("Test", ApplicationType.Web, StarterKit.Livewire, DatabaseEngine.Sqlite, false), "test.vbproject");
        return workspace;
    }

    private sealed class InMemoryDocumentStore : IProjectDocumentStore
    {
        public ProjectDocument? Document { get; private set; }
        public Task<ProjectDocument> LoadAsync(string path, CancellationToken cancellationToken = default) => Task.FromResult(Document!);
        public Task SaveAsync(string path, ProjectDocument document, CancellationToken cancellationToken = default) { Document = document; return Task.CompletedTask; }
    }

    private sealed class InMemoryRecentStore : IRecentProjectsStore
    {
        public Task<IReadOnlyList<RecentProject>> LoadAsync(CancellationToken cancellationToken = default) => Task.FromResult<IReadOnlyList<RecentProject>>([]);
        public Task RecordAsync(RecentProject project, CancellationToken cancellationToken = default) => Task.CompletedTask;
    }
}
