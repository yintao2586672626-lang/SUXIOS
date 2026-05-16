# AI Model Config

Unified entry: `ai_model_configs`.

Required runtime secret:

```env
AI_CONFIG_SECRET=change-me
```

Create provider config through existing API:

```http
POST /api/ai-config/providers/quick-setup
Content-Type: application/json

{
  "provider": "deepseek",
  "api_key": "sk-***",
  "base_url": "https://api.deepseek.com",
  "models": [
    {
      "model_key": "deepseek_chat",
      "model_name": "deepseek-chat",
      "is_enabled": 1
    },
    {
      "model_key": "deepseek_reasoner",
      "model_name": "deepseek-reasoner",
      "is_enabled": 1
    }
  ]
}
```

Used by:

- `POST /api/agent/test-llm`
- `POST /api/agent/ota-diagnosis`
- `POST /api/agent/analyze-captured-ota-data`
- `POST /api/agent/summarize-captured-ota-analysis`
- `POST /api/agent/feasibility-report/generate`
- `POST /api/ai/feasibility`

Provider API keys are not read from `DEEPSEEK_API_KEY` or `OPENAI_API_KEY`; they must be saved in `ai_model_configs`.
