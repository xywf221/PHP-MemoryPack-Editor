#:package MemoryPack@1.21.4

using MemoryPack;

if (args.Length < 1)
{
    Console.Error.WriteLine("Usage: v1-write|v1-read|v2-read|shape-write|shape-read|payload-write|payload-read [base64]");
    return 2;
}

// ---------------------------------------------------------------------------
// V1 / V2 schema migration commands
// ---------------------------------------------------------------------------

if (args[0] == "v1-write")
{
    var v = new V1 { Id = 42, Name = "雷少", Score = 100 };
    Console.WriteLine(Convert.ToBase64String(MemoryPackSerializer.Serialize(v)));
    return 0;
}

if (args[0] == "v1-read")
{
    if (args.Length < 2) { Console.Error.WriteLine("Missing base64."); return 2; }
    var v = MemoryPackSerializer.Deserialize<V1>(Convert.FromBase64String(args[1]));
    Assert(v != null, "v1 not null");
    Assert(v!.Id == 42, "v1.id");
    Assert(v.Name == "雷少", "v1.name");
    Assert(v.Score == 100, "v1.score");
    Console.WriteLine("ok");
    return 0;
}

if (args[0] == "v2-read")
{
    if (args.Length < 2) { Console.Error.WriteLine("Missing base64."); return 2; }
    var v = MemoryPackSerializer.Deserialize<V2>(Convert.FromBase64String(args[1]));
    Assert(v != null, "v2 not null");
    Assert(v!.Name == "雷少", "v2.name");
    Assert(v.Id == 42, "v2.id");
    Assert(v.Points == 100, "v2.points");
    Assert(v.Level == 5, "v2.level");
    Console.WriteLine("ok");
    return 0;
}

// ---------------------------------------------------------------------------
// Shape / Payload commands (unchanged)
// ---------------------------------------------------------------------------

if (args[0] == "shape-write")
{
    var shape = new Shape
    {
        Origin = new Point { X = 1, Y = 2 },
        Points = new[] { new Point { X = 3, Y = 4 }, new Point { X = 5, Y = 6 } },
    };
    Console.WriteLine(Convert.ToBase64String(MemoryPackSerializer.Serialize(shape)));
    return 0;
}

if (args[0] == "shape-read")
{
    if (args.Length < 2) { Console.Error.WriteLine("Missing base64."); return 2; }
    var shape = MemoryPackSerializer.Deserialize<Shape>(Convert.FromBase64String(args[1]));
    Assert(shape != null, "shape not null");
    Assert(shape!.Origin.X == 99 && shape.Origin.Y == 2, "shape origin");
    Assert(shape.Points is [{ X: 3, Y: 4 }, { X: 5, Y: 6 }], "shape points");
    Console.WriteLine("ok");
    return 0;
}

if (args[0] == "payload-write")
{
    var payload = new InteropPayload
    {
        Id = 42,
        Name = "雷少",
        Active = true,
        Scores = new[] { 3, 5, 8 },
        Tags = new List<string> { "php", "csharp" },
        Counts = new Dictionary<string, int> { ["alpha"] = 10, ["beta"] = 20 },
        Origin = new Point { X = 9, Y = 4 },
    };
    Console.WriteLine(Convert.ToBase64String(MemoryPackSerializer.Serialize(payload)));
    return 0;
}

if (args[0] == "payload-read")
{
    if (args.Length < 2) { Console.Error.WriteLine("Missing base64."); return 2; }
    var payload = MemoryPackSerializer.Deserialize<InteropPayload>(Convert.FromBase64String(args[1]));
    Assert(payload != null, "payload not null");
    Assert(payload!.Id == 42, "id");
    Assert(payload.Name == "雷少", "name");
    Assert(payload.Active, "active");
    Assert(payload.Scores is [3, 5, 8], "scores");
    Assert(payload.Tags is ["php", "csharp"], "tags");
    Assert(payload.Counts.Count == 2 && payload.Counts["alpha"] == 10 && payload.Counts["beta"] == 20, "counts");
    Assert(payload.Origin.X == 9 && payload.Origin.Y == 4, "origin");
    Console.WriteLine("ok");
    return 0;
}

Console.Error.WriteLine("Unknown command.");
return 2;

static void Assert(bool condition, string name)
{
    if (!condition)
        throw new InvalidOperationException($"Interop assertion failed: {name}");
}

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

[MemoryPackable]
public partial struct Point
{
    public int X { get; set; }
    public int Y { get; set; }
}

[MemoryPackable]
public partial class Shape
{
    public Point Origin { get; set; } = new();
    public Point[] Points { get; set; } = Array.Empty<Point>();
}

[MemoryPackable]
public partial class InteropPayload
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
    public bool Active { get; set; }
    public int[] Scores { get; set; } = Array.Empty<int>();
    public List<string> Tags { get; set; } = new();
    public Dictionary<string, int> Counts { get; set; } = new();
    public Point Origin { get; set; } = new();
}

// V1: old schema — { id, name, score }
[MemoryPackable]
public partial class V1
{
    public int Id { get; set; }
    public string Name { get; set; } = "";
    public int Score { get; set; }
}

// V2: new schema — { name, id, points, level }
//   reordered, renamed score→points, added level
[MemoryPackable]
public partial class V2
{
    public string Name { get; set; } = "";
    public int Id { get; set; }
    public int Points { get; set; }
    public int Level { get; set; }
}
