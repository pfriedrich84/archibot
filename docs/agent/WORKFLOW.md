# Agent Workflow

When working on this repository:

1. Inspect existing files before changing code.
2. Identify whether the change affects:
   - Python worker
   - Laravel backend
   - Svelte frontend
   - Docker/deployment
   - prompts
   - documentation
3. Make the smallest useful change.
4. Preserve existing behavior unless the task explicitly changes it.
5. Update docs when architecture, config, commands or user workflows change.
6. Add or update tests for non-trivial behavior.
7. Run relevant checks from `docs/agent/CHECKS.md`.

## Documentation expectations

Update:
- `README.md` for user-facing features
- `docs/configuration.md` for env/settings changes
- `docs/architecture.md` for pipeline/dataflow changes
- `docs/workflow.md` for review/classification behavior
- `docs/mcp.md` for MCP changes
