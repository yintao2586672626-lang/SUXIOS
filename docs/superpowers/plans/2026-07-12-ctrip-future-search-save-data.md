# 携程未来搜索整页保存 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use test-driven-development while implementing each behavior. This shared dirty worktree must be preserved; do not create a second worktree or touch unrelated files.

**Goal:** 在携程未来搜索面板顶部增加“保存数据”按钮，将当前酒店完整30天累计/昨日、本店/竞争圈数据保存为一张可下载的 PNG 图片。

**Architecture:** 复用现有竞争圈数据的 `buildCtripBusinessCanvasStatic` 与 `downloadBlob`，不新增数据库表或采集接口。未来搜索数据本身已经由获取接口入库，本功能只把当前已回读的完整30天结果整理成导出模型并下载。

**Tech Stack:** Vue 3 CDN、Canvas、Node `node:test`、现有携程静态 helper。

---

### Task 1: 定义完整30天导出契约

**Files:**
- Modify: `tests/automation/ctrip_search_opportunity_static.test.mjs`
- Modify: `public/index.html`

- [ ] **Step 1: Write the failing test**

断言未来搜索面板包含“保存数据”按钮，按钮调用 `downloadCtripSearchOpportunityImage`，处理函数使用 `ctripSearchOpportunityRows.value` 而不是当前 3/7/15 天的 `ctripSearchOpportunityVisibleRows`。

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test --test-name-pattern="future search panel saves the complete thirty-day dataset" tests/automation/ctrip_search_opportunity_static.test.mjs`

Expected: FAIL because the button and handler do not exist.

- [ ] **Step 3: Write minimal implementation**

在面板顶部加入按钮和保存状态；构建含日期、累计 PV/UV/转化率/预计订单、昨日新增对应数据的导出表，并调用现有 Canvas 下载能力。

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test --test-name-pattern="future search panel saves the complete thirty-day dataset" tests/automation/ctrip_search_opportunity_static.test.mjs`

Expected: PASS.

### Task 2: 验证按钮与下载结果

**Files:**
- Verify: `public/index.html`
- Verify: `tests/automation/ctrip_search_opportunity_static.test.mjs`

- [ ] **Step 1: Run complete focused tests**

Run: `node --test tests/automation/ctrip_search_opportunity_static.test.mjs`

Expected: all tests pass.

- [ ] **Step 2: Run protected entry and syntax checks**

Run: `npm.cmd run verify:public-entry`

Expected: `Public entry guard passed.`

- [ ] **Step 3: Verify in the local browser**

打开携程数据 → 流量数据 → 未来搜索，确认按钮位于面板右上方；点击后文件名包含酒店名和数据日期，成功提示明确为“整页数据图片已保存”。
