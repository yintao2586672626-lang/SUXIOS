# TypeScript 迁移指南
# ===================

## 当前状态
项目为 **PHP + Vue3(CDN) + 原生 JS** 架构，TypeScript 配置已就绪。

## 文件结构
```
JD-main/
├── tsconfig.json              # 主配置（类型检查用，noEmit）
├── tsconfig.build.json        # 生产构建配置（输出 JS 到 dist/）
├── package.json               # 依赖声明 & 脚本
├── .vscode/settings.json      # VSCode 编辑器设置
└── HOTEL/
    ├── types/                 # ★ 所有类型定义集中在此
    │   ├── index.ts           # 统一导出入口
    │   ├── global.d.ts        # 全局类型扩展（Vue/DOM）
    │   ├── common.ts          # 通用工具类型（分页、状态码等）
    │   ├── api.ts             # API 请求/响应 / Cookie 管理
    │   ├── user.ts            # 用户/权限相关类型
    │   ├── hotel.ts           # 酒店业务类型
    │   ├── online-data.ts     # 线上数据获取（携程/美团）
    │   └── system.ts          # 系统配置 / 路由 / Tab 枚举
    └── public/
        ├── app-main.js        # 当前主逻辑（JS，待迁移）
        └── app-main.d.ts      # app-main.js 的类型声明（已创建）
```

## 快速开始

### 1. 安装依赖
```bash
npm install
```

### 2. 类型检查（不编译输出）
```bash
npm run type-check
```

### 3. 实时监控类型错误
```bash
npm run type-check:watch
```

### 4. 编译 TS → JS（生产构建）
```bash
npm run build
```

## 迁移步骤建议

### 阶段 1：类型标注（不改变功能）
1. 将 `app-main.js` 重命名为 `app-main.ts`
2. 用已定义的类型标注 `ref()`、`computed()` 的泛型参数
3. 为函数添加参数和返回值类型
4. 修复 tsc 报出的所有类型错误

### 阶段 2：模块拆分（可选）
按功能域将 `app-main.ts` 拆分为独立模块：
```
src/
├── main.ts                  # 入口：createApp + 注册组件
├── composables/
│   ├── useAuth.ts           # 登录/登出/Token
│   ├── useOnlineData.ts     # 线上数据获取
│   └── useSystemConfig.ts   # 系统配置
├── components/
│   └── *.ts                 # 组件定义
└── api/
    └── request.ts           # 封装 fetch/axios
```

### 阶段 3：构建集成
- 引入 Vite 或 esbuild 作为构建工具
- 将 CDN 引入的 Vue 改为 npm 安装
- 支持 SFC（`.vue` 单文件组件）

## 类型使用示例

```typescript
import type { User, Hotel, ApiResponse } from '@/types';

// 标注 ref 类型
const user = ref<User | null>(null);
const hotels = ref<Hotel[]>([]);
const loading = ref<boolean>(false);

// API 响应类型
const res: ApiResponse<User> = await request('/api/auth/login', {
  username: 'admin',
  password: 'admin123',
});
```
