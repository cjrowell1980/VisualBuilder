using VisualBuilder.Application.Models;
using VisualBuilder.Application.Pages;
using VisualBuilder.Application.Projects;
using VisualBuilder.Domain.Projects;

namespace VisualBuilder.Domain.Tests;

public sealed class PageDesignerTests
{
    [Fact]
    public async Task Adds_edits_orders_and_removes_pages_and_controls()
    {
        var workspace = await Workspace();
        var model = new ModelDesigner(workspace).AddModel(new("Customer", "customers", true, false));
        var field = new ModelDesigner(workspace).AddField(model.Id, new("name", "Name", "string", false, false, false, null, []));
        var designer = new PageDesigner(workspace);
        var page = designer.AddPage(new("Customers", "", "index", "app", model.Id));
        var heading = designer.AddControl(page.Id, new("heading", "Customers", "full", null, new Dictionary<string, object?>()));
        var input = designer.AddControl(page.Id, new("input", "Name", "half", field.Id, new Dictionary<string, object?>()));

        designer.MoveControl(page.Id, input.Id, -1);
        var updated = workspace.Current!.Iterations[0].Pages.Single();
        Assert.Equal("customers", updated.Slug);
        Assert.Equal(input.Id, updated.Controls[0].Id);

        designer.UpdateControl(page.Id, input.Id, new("input", "Customer name", "full", field.Id, new Dictionary<string, object?>()));
        designer.RemoveControl(page.Id, heading.Id);
        Assert.Equal("Customer name", workspace.Current.Iterations[0].Pages[0].Controls.Single().Label);

        designer.RemovePage(page.Id);
        Assert.Empty(workspace.Current.Iterations[0].Pages);
    }

    [Fact]
    public async Task Uses_placeholder_defaults_when_names_are_blank()
    {
        var workspace = await Workspace();
        var models = new ModelDesigner(workspace);
        var defaultModel = models.AddModel(new("", "", true, false));
        var defaultField = models.AddField(defaultModel.Id, new("", "", "", false, false, false, null, []));
        var target = models.AddModel(new("Customer", "customers", true, false));
        var relation = models.AddRelationship(defaultModel.Id, new("", "belongs-to", target.Id, "", ""));
        var page = new PageDesigner(workspace).AddPage(new("", "", "", "", null));

        Assert.Equal("CustomerOrder", defaultModel.Name);
        Assert.Equal("customer_orders", defaultModel.TableName);
        Assert.Equal("number", defaultField.Name);
        Assert.Equal("Number", defaultField.Label);
        Assert.Equal("customer", relation.Name);
        Assert.Equal("customer_id", relation.ForeignKey);
        var manyToMany = models.AddRelationship(defaultModel.Id, new("customers", "belongs-to-many", target.Id, "", ""));
        Assert.Equal("customer_customer_order", manyToMany.PivotTable);
        Assert.Equal("Customer list", page.Name);
        Assert.Equal("customer-list", page.Slug);
    }

    [Fact]
    public async Task Rejects_duplicate_slugs_and_fields_from_another_model()
    {
        var workspace = await Workspace();
        var models = new ModelDesigner(workspace);
        var customer = models.AddModel(new("Customer", "customers", true, false));
        var order = models.AddModel(new("Order", "orders", true, false));
        var orderNumber = models.AddField(order.Id, new("number", "Number", "string", false, false, false, null, []));
        var designer = new PageDesigner(workspace);
        var page = designer.AddPage(new("Customers", "customers", "index", "app", customer.Id));

        Assert.Throws<PageDesignException>(() => designer.AddPage(new("Other", "customers", "custom", "app", null)));
        Assert.Throws<PageDesignException>(() => designer.AddControl(page.Id,
            new("input", "Number", "full", orderNumber.Id, new Dictionary<string, object?>())));
    }

    [Fact]
    public async Task Stores_page_categories_and_protects_parent_pages()
    {
        var workspace = await Workspace();
        var designer = new PageDesigner(workspace);
        var parent = designer.AddPage(new("Orders", "orders", "index", "app", null, "Sales"));
        var child = designer.AddPage(new("New order", "orders-create", "create", "app", null, "Sales / Orders", parent.Id));

        Assert.Equal(parent.Id, child.ParentPageId);
        Assert.Equal("Sales / Orders", child.Category);
        Assert.Throws<PageDesignException>(() => designer.RemovePage(parent.Id));
    }

    [Fact]
    public async Task Bound_pages_and_controls_protect_their_models_and_fields()
    {
        var workspace = await Workspace();
        var models = new ModelDesigner(workspace);
        var customer = models.AddModel(new("Customer", "customers", true, false));
        var email = models.AddField(customer.Id, new("email", "Email", "string", false, false, false, null, []));
        var pages = new PageDesigner(workspace);
        var page = pages.AddPage(new("Customers", "customers", "index", "app", customer.Id));
        var control = pages.AddControl(page.Id, new("input", "Email", "full", email.Id, new Dictionary<string, object?>()));

        Assert.Throws<ModelDesignException>(() => models.RemoveModel(customer.Id));
        Assert.Throws<ModelDesignException>(() => models.RemoveField(customer.Id, email.Id));
        pages.RemoveControl(page.Id, control.Id);
        pages.RemovePage(page.Id);
        models.RemoveField(customer.Id, email.Id);
        models.RemoveModel(customer.Id);
        Assert.Empty(workspace.Current!.Iterations[0].Models);
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
