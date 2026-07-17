---
name: project-knowledge
description: Project knowledge and documentation agent. Answers queries about codebase structure, conventions, patterns, files, and architecture. Auto-updates AGENTS.md after codebase changes. Handles doc inspection and maintenance.
metadata:
  version: "1.0.0"
---

# Project Knowledge Agent

Manages project documentation and answers queries using AGENTS.md and other doc files. Whenever codebase changes are made, it ensures AGENTS.md stays in sync.

## When to Use

- Asked "how does X work" or "what is the structure of Y" — answer using docs
- Asked about project conventions, patterns, files, or architecture
- Making codebase changes (adding/removing/renaming files, restructuring directories) — auto-update AGENTS.md
- Explicitly asked to "update docs" or "refresh documentation"
- Need to verify project structure or find relevant files

## Core Reference Files

| File | Purpose |
|---|---|
| `AGENTS.md` | Primary project documentation (directory layout, page patterns, quirks, doc inventory) |
| `public_html/admin/AI_SYSTEM_README.md` | Deep technical reference — full schema, business flows, migration patterns, postmortems |
| `docs/software-requirements-specification.md` | Full SRS (585 lines) |
| `docs/software-architecture.md` | Architecture documentation |
| `docs/business-requirements-document.md` | Business requirements |
| `public_html/admin/software_overview.md` | Admin software overview |
| `public_html/admin/extended_overview.md` | Extended admin documentation |
| `public_html/admin/UX-EXECUTION-PLAN.md` | AI Assistant UX execution plan |
| `UX-EXECUTION-PLAN.md` | Rent Cylinder Manager UX plan |
| `docs/testing/DEEP_FUNCTIONAL_TEST_PLAN.md` | Deep functional integration test plan v2.0 — DB-level side-effect verification for every business + platform admin operation |
| `docs/testing/COMPREHENSIVE_TEST_PLAN.md` | Full E2E browser test plan (all phases P0-P4 including AI, public site, blog, SEO) |

## Behavior

### Query Mode
When user asks a project-related question:
1. Read `AGENTS.md` for high-level context (layout, patterns, quirks)
2. If question involves schema, flows, or deep technical detail → also read `admin/AI_SYSTEM_README.md`
3. If question involves formal specs → read relevant `docs/*.md`
4. If question involves testing → read `docs/testing/DEEP_FUNCTIONAL_TEST_PLAN.md` (deep DB verification) and/or `docs/testing/COMPREHENSIVE_TEST_PLAN.md` (browser-level)
5. If needed, explore specific files/directories with glob/grep
6. Answer concisely with file path references (e.g., `admin/cylinders.php:42`)

### Auto-Update Mode (during codebase changes)
After making ANY codebase change (adding/removing/renaming files, restructuring), ALWAYS:
1. Check if `AGENTS.md` needs updating by comparing the change against its content
2. Update affected sections:
   - **Layout table** — if directories were added/removed
   - **Admin/Portal file listings** — if admin/portal files changed
   - **Public site structure** — if `public_html/` root files changed
   - **File listing tables** — if admin sub-app or AI subsystem files changed
   - **Critical quirks** — if new conventions or patterns were introduced
   - **Documentation inventory** — if .md files were added/removed/renamed
3. Verify updated AGENTS.md reads correctly

### Explicit Update Mode
When user says "update docs" or similar:
1. Full scan of these directories:
   - `public_html/` (root) — list all .php/.html files
   - `public_html/admin/` — list all .php files
   - `public_html/admin/ai/` — recursive file listing
   - `public_html/portal/` — list all .php files
   - `docs/` — list all .md files
2. Cross-reference with `AGENTS.md` current content
3. Propose and apply updates to any outdated sections
4. Report what changed

## Workflow

1. Parse user intent:
   - Query about project → **Query Mode**
   - Making/requesting code changes → **Auto-Update Mode** (after changes)
   - "Update docs" or "refresh" → **Explicit Update Mode**
2. Execute with appropriate reference files
3. Confirm completion
