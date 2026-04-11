/* ============================================
   app-main.d.ts - app-main.js 的类型声明
   为现有的 JS 文件提供类型提示和检查
   ============================================ */

import type {
  User,
  Hotel,
  HotelOption,
  SystemConfig,
  PageRoute,
  OnlineDataTab,
  DownloadCenterTab,
  CtripForm,
  CtripTrafficForm,
  MeituanForm,
  MeituanTrafficForm,
  MeituanCommentForm,
} from './types';

/** Vue App 实例返回的状态集合 */
export interface AppState {
  // ---- 认证状态 ----
  isLoggedIn: boolean;
  loading: boolean;
  user: User | null;
  token: string;

  // ---- UI 状态 ----
  currentTime: string;
  currentPage: PageRoute;
  showPassword: boolean;
  sidebarCollapsed: boolean;

  // ---- 登录表单 ----
  loginForm: { username: string; password: string };
  rememberUsername: boolean;

  // ---- 系统配置 ----
  systemConfig: SystemConfig;

  // ---- 线上数据获取 Tab ----
  onlineDataTab: string;       // 实际为 OnlineDataTab | CtripTab | MeituanTab 联合类型
  downloadCenterTab: DownloadCenterTab;
  fetchingData: boolean;
  onlineDataResult: unknown;
  latestTrafficData: unknown;
  topTenHotels: unknown[];
  ctripHotelsList: unknown[];
  ctripTableTab: string;
  showRawData: boolean;

  // ---- 表单数据 ----
  ctripForm: CtripForm;
  ctripTrafficForm: CtripTrafficForm;
  meituanForm: MeituanForm;
  meituanTrafficForm: MeituanTrafficForm;
  meituanCommentForm: MeituanCommentForm;

  // ---- 数据列表 ----
  hotels: Hotel[];
  users: User[];
  onlineDataHotelList: HotelOption[];

  // ... 其他业务字段可按需补充
}

/** 全局函数声明 */
export declare function handleLogin(): Promise<void>;
export declare function handleLogout(): void;
export declare function copyCookieScript(): void;
export declare function openTargetSite(url: string): void;
export declare function loadMeituanConfig(): void;
export declare function loadMeituanConfigList(): void;
export declare function switchToMeituanDownloadCenter(): void;
