# DeepSeek Claude Code Worker MCP

This repository includes a project-level MCP server for Claude Code.

## Setup

Set the DeepSeek key in the same shell that starts Claude Code:

```powershell
$env:DEEPSEEK_API_KEY = "sk-..."
```

Optional:

```powershell
$env:DEEPSEEK_MODEL = "deepseek-chat"
$env:DEEPSEEK_BASE_URL = "https://api.deepseek.com"
```

Claude Code should detect `.mcp.json` at the repository root and start:

```text
deepseek-worker
```

## Tools

- `ask`: send an analysis prompt to DeepSeek.
- `bulk_read`: read selected repository files and ask DeepSeek to analyze them.

