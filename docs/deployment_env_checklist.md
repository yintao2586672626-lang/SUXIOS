# 生产环境配置验收清单

更新时间：2026-05-30

## 使用方式

生产配置不得提交到 Git。可从 `.example.production.env` 复制到受控位置，替换所有 `CHANGE_ME` 值后运行：

```powershell
$env:RELEASE_ENV_FILE='D:\path\to\production.env'
npm.cmd run review:release-readiness
```

不要直接把 `.example.production.env` 当作生产配置使用；发布检查会拒绝 `TODO`、`CHANGE_ME`、`example` 等占位值。

## 必需项

| 配置项 | 生产要求 | 说明 |
|---|---|---|
| `APP_DEBUG` | `false` | 生产环境禁止调试输出 |
| `DB_HOST` | 生产数据库地址 | 不使用本地开发库 |
| `DB_NAME` | 生产数据库名 | 与发布库一致 |
| `DB_USER` | 最小权限账号 | 不使用 root |
| `DB_PASS` | 非空强密码 | 不使用空密码 |
| `AI_CONFIG_SECRET` | 非空且稳定 | 必须与保存 `ai_model_configs.api_key_encrypted` 时一致 |

## OpenAI / LLM 配置

当前生产 AI 调用入口为 `LlmClient`，模型、Base URL、API Key 存储在数据库 `ai_model_configs` 中，API Key 使用 `AI_CONFIG_SECRET` 加密。

上线前必须确认：

- 至少一个生产模型配置已启用。
- `base_url` 指向授权供应商地址。
- `model_name` 是实际可用模型。
- `api_key_encrypted` 可被生产 `AI_CONFIG_SECRET` 解密。
- 完成一次受控真实连通性验证，并按 `docs/llm_connectivity_attestation.example.json` 记录状态；生产验收可通过 `LLM_CONNECTIVITY_ATTESTATION_FILE` 指向受控 JSON 文件。

## 不允许

- 把 `.env`、生产 env、API Key、OTA Cookie/Token 提交到 Git。
- 用本地开发 `.env` 作为生产配置验收依据。
- 使用空数据库密码或 root 账号发布。
