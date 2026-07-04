# Quiote Assistant MCP — plan

Status: proposal / planning
Depends on: `docs/MCP_SERVER_PLAN.md` (the app-as-MCP-server capability this is built on)

## 1. What it is

An MCP server you plug into **Claude Code / GitHub Copilot / Cursor / any MCP client** that gives
the AI agent (a) authoritative **knowledge of Quiote** — conventions, APIs, how to build things —
and (b) **tools to introspect and scaffold** a real Quiote project. Think "Laravel Boost" /
"Symfony MCP", but for Quiote.

**Dogfooding:** we build it *as a Quiote application* using Quiote's own app-as-MCP-server
capability (`#[McpTool]` / `#[McpResource]` / `#[McpPrompt]` + the stdio transport from
`docs/MCP_SERVER_PLAN.md`). So shipping the assistant is simultaneously the best acceptance test of
that capability — if the assistant works, the feature works — and its source becomes the reference
example for anyone exposing their own Quiote app over MCP.

## 2. Two modes

| Mode | Needs a project? | Purpose |
|------|------------------|---------|
| **Knowledge** | No | Answer "how do I do X in Quiote?" and hand back idiomatic patterns/docs. Works anywhere, before a project even exists. |
| **Project-aware** | Yes (`--app-dir`) | Introspect *this* app (routes, config, DB connections, plugins) and scaffold new code that fits it. |

Knowledge mode is the fast, high-value core (ships first). Project-aware mode layers on top when
launched inside a Quiote app.

## 3. What it exposes

### Resources (read-only knowledge)
- The canonical `docs/*.md` (architecture, config, database, routing, actions, plugins, MCP, …),
  each addressable as an MCP resource so the agent can pull authoritative text with citations.
- **Convention cards** — short, task-oriented notes the docs don't spell out crisply: the
  class-per-action `#[Route]` model (verbs → `executeRead/Write/...`), config file formats
  (XML/PHP/YAML), the DI attributes, the `PluginRegistrar` surface, validator declaration,
  database driver aliases.
- **API reference** — reflection-generated signatures + docblocks for key classes/attributes, so
  it never drifts from the code.

### Prompts (reusable, parameterized templates)
`new-module`, `add-action`, `add-service`, `add-plugin`, `add-db-connection`, `expose-mcp-tool` —
each stitches the relevant convention card + a recipe so the agent emits idiomatic code.

### Tools
**Knowledge (project-agnostic):**
- `search_docs(query)` → ranked doc/convention excerpts with source citations.
- `get_convention(topic)` → a convention card (`actions`, `routing`, `config`, `di`, `plugins`,
  `database`, `validation`, `mcp`, …).
- `get_recipe(task)` → step-by-step instructions + runnable code for a task.
- `describe_symbol(fqcn)` / `list_api(namespace?)` → reflection-based signatures + docs.
- `quiote_version()`.

**Project-aware (require `--app-dir`; all read-only unless noted):**
- `project_info()` — env, contexts, enabled plugins, module list.
- `list_routes()` — via `AttributeRouteScanner` → `RoutePlan` (+ its diagnostics).
- `describe_action("Module.Action")` — verbs, validators (→ JSON Schema via the Validator IR),
  view, required credentials.
- `list_db_connections()` — from `DatabaseConfigHandler`'s canonical array.
- `list_plugins()`, `list_modules()`, `read_config(key)` — `Config::get` over a whitelisted key set.
- `scaffold_module(name)`, `scaffold_action(module, name, verbs)`, `scaffold_plugin(...)`,
  `scaffold_db_connection(...)` — **write** files following conventions; support `dry_run` and
  return a diff for the agent/user to approve.
- `run_console(command, args)` — a **whitelisted, non-destructive** subset (`routes:list`,
  `cache:warmup`, `about`); never migrations/deletes.

## 4. Architecture (as a Quiote app)

A minimal Quiote application (its own repo / package `quioteframework/quiote-mcp-assistant`, or an
`apps/mcp-assistant/` in-repo) with:
- An `Mcp/` module of `#[McpTool]` / `#[McpResource]` / `#[McpPrompt]` classes — resolved through
  Quiote's DI exactly like any capability from the capability plan.
- The knowledge base bundled as resource files + a small search index for `search_docs`.
- A `quiote-assistant` bin (thin wrapper over `mcp:serve` stdio) as the client entry point.
- Project-introspection tools that bootstrap the **target** app read-only via `Kernel` /
  `Quiote::bootstrap($appDir)` in a no-write sandbox, then use `AttributeRouteScanner`,
  `DatabaseConfigHandler`, `Config`, `PluginManager`, and reflection.

Because it's a normal Quiote app, it also gets OTel, logging, and config for free.

## 5. Distribution — making it trivial to plug in

MCP clients launch servers as stdio subprocesses (most common) or connect over HTTP. Ship stdio
first. Provide copy-paste client config:

```jsonc
// Claude Code / generic MCP client
{ "mcpServers": {
    "quiote": { "command": "php", "args": ["/path/to/vendor/bin/quiote-assistant"] }
} }
```
```bash
# Claude Code CLI
claude mcp add quiote -- php /path/to/vendor/bin/quiote-assistant --app-dir=.
```

Deliverables for zero-friction install:
- Composer package with a `quiote-assistant` bin.
- A **PHAR** build for no-install usage.
- Docs snippets for Claude Code, Copilot, and Cursor.
- An HTTP transport option (from the capability plan) for shared/team deployments.

## 6. Keeping the knowledge current (don't let it rot)

- Knowledge base is **generated at build/release time** from the canonical `docs/*.md` + reflection
  over the framework, not hand-maintained in parallel. Convention cards and recipes are the only
  hand-authored parts.
- **CI dogfood check:** because the assistant is a Quiote app, CI asserts that every recipe's code
  actually scaffolds/compiles against the current framework — so a breaking API change fails the
  recipe test, not the user.
- KB is **versioned to the framework version** (`quiote_version()` and resource metadata) so the
  agent knows which Quiote it's advising for.

## 7. Safety

- Read-only by default. Scaffolding tools are explicit, support `dry_run`, and return diffs for
  approval; `run_console` is whitelisted to non-destructive commands; the assistant never touches
  DB data.
- Honor MCP **roots** — operate only within the project root the client granted.
- Project bootstrap of the target app runs in a no-write/sandboxed mode.

## 8. Phasing

1. **Knowledge-only stdio server** — docs-as-resources + `search_docs` + `get_convention` + the
   core prompts. This alone is "plug into Claude Code and it knows Quiote." Ships fast, no project
   bootstrap. Proves the capability's Phase 1 + 5 (stdio, tools/resources/prompts).
2. **Reflection API reference + recipes** (`describe_symbol`, `get_recipe`).
3. **Project-aware introspection** (`list_routes`, `describe_action`, `read_config`,
   `list_plugins`, `list_db_connections`) — read-only.
4. **Scaffolding** (`scaffold_*` with dry-run/diff) + safe `run_console`.
5. **Distribution polish** — PHAR, client-config docs, `claude mcp add` snippet, HTTP option.
6. **KB freshness** — build-time generation from docs+reflection, CI recipe-compile checks,
   version tagging.

## 9. Testing

- The assistant is a Quiote app → integration-test it with `mcp/sdk`'s **client** (stdio):
  `tools/list` includes the expected tools; `search_docs("routing")` returns cited hits;
  `describe_action` against the sandbox app matches known routes; scaffolding in `dry_run` returns
  a sensible diff. Tag `#[Group('integration')]`.
- Recipe code samples compiled/run in CI (the dogfood check, §6).

## 10. Open questions

- **Bundle vs generate** the knowledge base — how much ships as static resource files vs generated
  from reflection at build time.
- **Multi-version KB** — advising for the framework version the user targets vs the latest.
- **Bootstrap isolation** — safely booting an arbitrary target app read-only (autoload, side
  effects, config cache) without mutating it.
- **Reuse for a docs site** — the same generated KB could power a static documentation site, not
  just MCP. Worth keeping the generator output format neutral.
