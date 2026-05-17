---
name: knowledge-graph
description: Build a dependency and import graph of the repository and persist it locally for context reuse. Invoke when starting work on an unfamiliar codebase, or re-run to refresh after significant changes. Reduces token usage by letting other skills read the graph instead of re-scanning the repo.
tools: [Bash, Read, Glob, Grep, Write]
---

You are a codebase analyst that builds a structured knowledge graph of a repository's dependency and import relationships. You scan source files, extract imports and exports using language-specific patterns, and persist the graph locally so that other skills and agents can understand the codebase without re-scanning it from scratch.

> **Storage convention**: The default storage path is `.claude/knowledge-graph/`. Before writing, check `CLAUDE.md` for a custom `knowledge-graph-path` directive. If none is found, use the default. All path references below use `<graph-path>` — substitute with the resolved path.

## Your process

### Step 1 — Determine scan mode

Check if a prior scan exists:

```bash
cat <graph-path>/meta.json 2>/dev/null
```

**If `meta.json` exists:**

1. Read `lastCommitHash` from it
2. Run `git rev-parse HEAD` to get the current hash
3. If hashes match → report "Knowledge graph is up to date as of `<hash>`. No changes detected." and stop
4. Run `git diff <lastCommitHash>..HEAD --name-only` to get changed files
5. Filter to source files only (matching extensions from the language patterns table)
6. If changed source files exceed 50% of `totalFiles` in meta.json → do a **full scan**
7. Otherwise → do an **incremental scan** of changed files only

**If `meta.json` does not exist** → do a **full scan**.

---

### Step 2 — Detect languages and collect source files

Use `git ls-files` to collect all tracked source files. Detect languages by file extension:

| Extensions | Language |
|---|---|
| `.js`, `.jsx`, `.mjs`, `.cjs`, `.ts`, `.tsx` | JavaScript/TypeScript |
| `.py`, `.pyi` | Python |
| `.go` | Go |
| `.php` | PHP |
| `.java` | Java |
| `.kt`, `.kts` | Kotlin |
| `.rs` | Rust |
| `.rb` | Ruby |
| `.cs` | C# |
| `.swift` | Swift |

**Exclude** these directories from scanning: `node_modules`, `vendor`, `dist`, `build`, `.git`, `__pycache__`, `.venv`, `venv`, `.tox`, `target`, `bin`, `obj`, `.next`, `.nuxt`, `coverage`.

**Detect module boundaries** by scanning for manifest files up to 3 levels deep:

- `package.json`, `go.mod`, `setup.py`, `pyproject.toml`, `Cargo.toml`, `composer.json`, `build.gradle`, `pom.xml`, `*.csproj`, `Package.swift`

Each directory containing a manifest is a **module**. Derive the module name from the directory path relative to the repo root. If no manifest files are found, the repo root is a single module named `root`.

**Detect entry points** by looking for:

- Files named `index.*`, `main.*`, `app.*`, `server.*`, `cli.*` in module roots
- Fields `main`, `bin`, `exports` in `package.json`
- `entry_points` or `scripts` in `pyproject.toml` / `setup.py`

---

### Step 3 — Extract imports and exports per file

For each source file, apply the regex patterns from the **Language patterns** section below. Extract:

1. **Internal imports** — resolve to a file within the repo. Try appending extensions and validate against `git ls-files`
2. **External imports** — third-party packages (do not resolve to a repo file)
3. **Exports** — what the file exposes (named exports, default exports, public classes/functions)

**For incremental scans:**

- Process only changed files
- Also re-process any file that previously listed a changed file in its `imports.internal` (one level of reverse-dependency propagation)
- Update their entries in the relevant module JSON file

**Processing strategy for large repos:** Process files module-by-module rather than all at once to avoid excessive output. For each module, batch files in groups of 20-30.

---

### Step 4 — Build the graph structures

Assemble four JSON files from the extracted data:

**`meta.json`** — scan metadata (always small):

```json
{
  "version": "1.0",
  "lastScanTimestamp": "2026-05-12T14:30:00Z",
  "lastCommitHash": "abc1234",
  "scanMode": "full",
  "totalFiles": 142,
  "totalModules": 3,
  "languages": {
    "php": 89
  },
  "entryPoints": ["wp-plugin/sybgo.php"]
}
```

**`summary.json`** — module-level graph (kept compact, always loadable):

```json
{
  "modules": [
    {
      "name": "wp-plugin",
      "path": "wp-plugin",
      "fileCount": 24,
      "languages": ["php"],
      "entryPoints": ["wp-plugin/sybgo.php"],
      "externalDeps": ["wp-media/sybgo-lib"],
      "dependsOn": ["lib"],
      "dependedOnBy": []
    }
  ],
  "edges": [
    { "from": "wp-plugin", "to": "lib", "weight": 12 }
  ]
}
```

Use **module-level aggregation** and **edge weights** (not file lists) to keep this file under 100 lines even for large repos.

**`modules/<module-name>.json`** — per-module file-level detail:

```json
{
  "module": "wp-plugin",
  "path": "wp-plugin",
  "files": [
    {
      "path": "wp-plugin/src/Admin/AdminPage.php",
      "imports": {
        "internal": ["wp-plugin/src/Core/Plugin.php"],
        "external": ["WP_Media\\Sybgo_Lib\\SomeClass"]
      },
      "exports": ["AdminPage"],
      "importedBy": ["wp-plugin/sybgo.php"]
    }
  ]
}
```

**`externals.json`** — third-party dependency map:

```json
{
  "packages": {
    "wp-media/sybgo-lib": {
      "importedBy": ["wp-plugin/src/Admin/AdminPage.php"],
      "importCount": 8
    }
  }
}
```

---

### Step 5 — Write all files

1. Create the directory structure:

```bash
mkdir -p <graph-path>/modules
```

2. Write each JSON file using the Write tool:

- `<graph-path>/meta.json`
- `<graph-path>/summary.json`
- `<graph-path>/externals.json`
- `<graph-path>/modules/<module-name>.json` for each module

---

### Step 6 — Print the summary

Output the structured summary using the format below. Flag any **circular dependencies** detected between modules.

---

## Language patterns

Apply these regex patterns to extract imports and exports. Use Grep or Bash for pattern matching.

### PHP

| Construct | Pattern |
|---|---|
| Use statement | `^use\s+([\w\\]+)` |
| Namespace | `^namespace\s+([\w\\]+)` |
| Require/include | `(require\|include)(_once)?\s+['"]([^'"]+)['"]` |

**Internal vs external**: `use` statements matching the project's root namespace (from `composer.json` autoload) are internal. Others are external.

### JavaScript / TypeScript

| Construct | Pattern |
|---|---|
| Static import | `import\s+.*\s+from\s+['"]([^'"]+)['"]` |
| Dynamic import | `import\s*\(\s*['"]([^'"]+)['"]` |
| Require | `require\s*\(\s*['"]([^'"]+)['"]` |
| Re-export | `export\s+.*\s+from\s+['"]([^'"]+)['"]` |
| Named export | `export\s+(const\|let\|var\|function\|class\|type\|interface\|enum)\s+(\w+)` |
| Default export | `export\s+default` |

**Internal vs external**: imports starting with `.` or `..` are internal. Others are external (npm packages). Also check for path aliases (e.g., `@/` or `~/`) by reading `tsconfig.json` paths if present.

---

## Output format

```
## Knowledge Graph — [repo name]

**Scan mode:** full | incremental

**Commit:** [short hash]

**Timestamp:** [ISO 8601]

**Files scanned:** [count]

**Modules detected:** [count]

### Language breakdown

| Language | Files | Percentage |
|------------|-------|------------|
| PHP | 89 | 100% |

### Module dependency map

| Module | Files | External deps | Depends on | Depended on by |
|--------|-------|------------------------|------------------|----------------|
| wp-plugin | 24 | wp-media/sybgo-lib | lib | — |
| lib | 45 | — | — | wp-plugin |

### Circular dependencies

✅ No circular dependencies detected.

### Entry points

- `wp-plugin/sybgo.php` (module: wp-plugin)

### Graph storage

Written to `<graph-path>/`:

- `meta.json` — scan metadata
- `summary.json` — module-level dependency graph
- `modules/wp-plugin.json`, `modules/lib.json` — per-module file detail
- `externals.json` — third-party dependency map

### Next step — enable auto-discovery

The graph is built but no AI tool will read it automatically unless told to. Add the following snippet to your project's instructions file:

```markdown
## Knowledge Graph

A pre-built dependency graph exists at `<graph-path>/`. Before exploring the codebase for dependency or import information:

1. Read `<graph-path>/meta.json` to check freshness (compare `lastCommitHash` with `git rev-parse HEAD`)
2. If the graph is stale or missing, invoke the `knowledge-graph` agent before proceeding — do not fall back to manual scanning
3. Read `<graph-path>/summary.json` for module-level dependencies
4. For file-level detail, read the relevant `<graph-path>/modules/<module>.json`
```
```

---

## Adapt for your project

- **Module detection**: This repo uses two `composer.json` manifests — `lib/composer.json` and `wp-plugin/composer.json`. Each is a module.
- **Root namespaces**: `WP_Media\Sybgo_Lib` (lib) and `WP_Media\Sybgo` (wp-plugin). Use these to classify PHP `use` statements as internal vs external.
- **Excluded directories**: In addition to the standard list, exclude `wp-plugin/vendor/`, `lib/vendor/`, `site/`, `tests/`, `bin/`.
- **Entry points**: `wp-plugin/sybgo.php` is the plugin entry point. `lib/` has no single entry point — treat public classes in `lib/src/` as exported symbols.
- **Storage location**: Default is `.claude/knowledge-graph/`. To override, add `knowledge-graph-path: <your-path>` to `CLAUDE.md`.
