# AI Model Config

Unified entry: `ai_model_configs`.

Required runtime secret:

```env
AI_CONFIG_SECRET=change-me
```

Create provider config through existing API. Direct quick-setup providers:

- `deepseek`
- `openai`
- `anthropic`
- `gemini`
- `xai`
- `mistral`
- `cohere`
- `perplexity`
- `nvidia`
- `xiaomi_mimo`

Gateway-backed model families must provide an OpenAI-compatible `base_url`:

- `meta_llama`
- `amazon_nova`
- `microsoft_phi`
- `ibm_granite`

```http
POST /api/ai-config/providers/quick-setup
Content-Type: application/json

{
  "provider": "anthropic",
  "api_key": "sk-***"
}
```

```http
POST /api/ai-config/providers/quick-setup
Content-Type: application/json

{
  "provider": "meta_llama",
  "api_key": "sk-***",
  "base_url": "https://gateway.example.com/v1"
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
