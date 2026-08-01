# 酒店图片输出契约

## 目录

- 结果模板
- 状态
- 渠道规则

## 结果模板

实际执行前先形成任务简报；能在执行后继续报告时补齐输出和 QA。使用等价字段即可，不伪造未知值。

```yaml
status: ready_to_edit | generated_unverified | review_required | storyboard_only | blocked_missing_source | blocked_provider | failed
mode: ota_fact_edit | brand_creative | video_storyboard
label: ota_fact_preserving_candidate | brand_creative_not_for_ota | storyboard_only
hotel_binding:
  hotel: <name-or-unverified>
  room_or_space: <name-or-unverified>
  set_consistency: single_image | verified_same_hotel | unverified | mixed_hotel_blocked
  source: <user_provided | project_asset | generated | unverified>
  source_date: <date-or-unverified>
target_channel: <ctrip | meituan | website | social | internal | unverified>
channel_spec: <verified-current-rule | user-provided | channel_spec_unverified>
input_roles:
  - image: <index-or-path>
    role: edit_target | scene_source | style_reference | lighting_reference | composition_reference | brand_identity_reference | previous_output
change_only:
  - <requested-change>
must_preserve:
  - <protected-hotel-fact>
changes_applied:
  - <actual-change-or-not-run>
unverified:
  - <unknown-or-not-checked>
visual_qa:
  result: pass | warning | failed | not_visually_verified
  warnings: []
output:
  path: <path-or-not-generated>
  actual_pixels: <width>x<height> | unverified
  original_overwritten: false
allowed_channels:
  - <channel>
prohibited_channels:
  - <channel>
```

## 状态

- `ready_to_edit`：输入、模式和提示词已明确，尚未调用工具。
- `generated_unverified`：文件已生成，但 AI 回检或现场核验尚未闭环。
- `review_required`：发现事实漂移、文字错误、伪影或渠道风险。
- `storyboard_only`：只生成视频分镜/提示词包。
- `blocked_missing_source`：缺少真实编辑底图或必要绑定。
- `blocked_provider`：请求实际视频，但没有已授权视频生成/剪辑路径。
- `failed`：执行失败；必须说明失败阶段。

不要使用 `completed`、`verified` 或“可直接上传”掩盖人工核验缺失。

## 渠道规则

- `ota_fact_preserving_candidate`：只允许进入人工核验队列；核验通过后由外部发布流程决定渠道。
- `brand_creative_not_for_ota`：允许品牌海报、创意提案、社媒创意或内部展示；图片和文案中应披露创意属性。
- `storyboard_only`：只表示分镜方案完成，不代表视频文件存在。
- 视频素材绑定未核验时只允许内部草案；`mixed_hotel_blocked` 不得进入同一成片。
- 平台规格没有当前官方证据时，不给出自信的固定尺寸/比例；写 `channel_spec_unverified`。
- 本 Skill 不执行 OTA 登录、账号授权、自动上传或发布。
