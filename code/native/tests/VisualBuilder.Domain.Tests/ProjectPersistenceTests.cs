using VisualBuilder.Domain.Projects;
using VisualBuilder.Infrastructure.Projects;
using VisualBuilder.Application.Models;
using VisualBuilder.Application.Projects;

namespace VisualBuilder.Domain.Tests;

public sealed class ProjectPersistenceTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), "VisualBuilder.Tests", Guid.NewGuid().ToString("N"));

    [Fact]
    public void Creates_a_project_with_a_normalized_slug_and_initial_iteration()
    {
        var timestamp = new DateTimeOffset(2026, 9, 5, 12, 0, 0, TimeSpan.Zero);
        var project = ProjectDocument.Create(new("Customer Portal", ApplicationType.Web, StarterKit.Livewire,
            DatabaseEngine.PostgreSql, true), timestamp);

        Assert.Equal("1.0", project.ContractVersion);
        Assert.Equal("customer-portal", project.Project.Slug);
        Assert.Single(project.Iterations);
        Assert.Equal(IterationStatus.Draft, project.Iterations[0].Status);
    }

    [Theory]
    [InlineData("Café Orders", "cafe-orders")]
    [InlineData("Stock & Sales!", "stock-sales")]
    [InlineData("東京", "project")]
    public void Slugs_remain_compatible_with_the_project_contract(string name, string expected) =>
        Assert.Equal(expected, Slug.Create(name));

    [Fact]
    public async Task Saves_and_reopens_a_project_document()
    {
        var path = Path.Combine(_directory, "customer-portal.vbproject");
        var expected = ProjectDocument.Create(new("Customer Portal", ApplicationType.WebApi, StarterKit.Livewire,
            DatabaseEngine.Sqlite, false));
        var store = new JsonProjectDocumentStore();

        await store.SaveAsync(path, expected);
        var actual = await store.LoadAsync(path);

        Assert.Equal(expected.ContractVersion, actual.ContractVersion);
        Assert.Equal(expected.Project, actual.Project);
        Assert.Equal(expected.Iterations[0].Id, actual.Iterations[0].Id);
        Assert.Equal(expected.Iterations[0].Name, actual.Iterations[0].Name);
        var json = await File.ReadAllTextAsync(path);
        Assert.Contains("\"applicationType\": \"web-api\"", json);
        Assert.Contains("\"starterKit\": \"livewire\"", json);
    }

    [Fact]
    public async Task Recent_projects_are_deduplicated_and_most_recent_first()
    {
        var settingsPath = Path.Combine(_directory, "recent-projects.json");
        var store = new JsonRecentProjectsStore(settingsPath);
        await store.RecordAsync(new("First", "C:\\Projects\\first.vbproject", DateTimeOffset.UtcNow.AddMinutes(-2)));
        await store.RecordAsync(new("Second", "C:\\Projects\\second.vbproject", DateTimeOffset.UtcNow.AddMinutes(-1)));
        await store.RecordAsync(new("First renamed", "C:\\Projects\\first.vbproject", DateTimeOffset.UtcNow));

        var recent = await store.LoadAsync();

        Assert.Equal(2, recent.Count);
        Assert.Equal("First renamed", recent[0].Name);
        Assert.Equal("Second", recent[1].Name);
    }

    [Fact]
    public async Task Reopens_models_fields_and_relationships_without_data_loss()
    {
        var path = Path.Combine(_directory, "orders.vbproject");
        var documents = new JsonProjectDocumentStore();
        var workspace = new ProjectWorkspace(documents, new JsonRecentProjectsStore(Path.Combine(_directory, "recent.json")));
        await workspace.CreateAsync(new("Orders", ApplicationType.Web, StarterKit.Livewire, DatabaseEngine.PostgreSql, true), path);
        var designer = new ModelDesigner(workspace);
        var customer = designer.AddModel(new("Customer", "customers", true, true));
        var order = designer.AddModel(new("Order", "orders", true, false));
        designer.AddField(customer.Id, new("email", "Email", "string", false, true, true, null, ["required", "email"]));
        designer.AddRelationship(order.Id, new("customer", "belongs-to", customer.Id, "customer_id", null));
        await workspace.SaveAsync();

        var reopened = await documents.LoadAsync(path);

        Assert.Equal(2, reopened.Iterations[0].Models.Count);
        Assert.Equal(["required", "email"], reopened.Iterations[0].Models[0].Fields[0].ValidationRules);
        Assert.Equal(customer.Id, reopened.Iterations[0].Models[1].Relationships[0].TargetModelId);
    }

    public void Dispose()
    {
        if (Directory.Exists(_directory)) Directory.Delete(_directory, true);
    }
}
