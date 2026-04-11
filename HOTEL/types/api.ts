/* ============================================
   API 请求/响应类型
   ============================================ */

import type { ApiResponse, PaginatedData } from './common';

/** 登录请求 */
export interface LoginRequest {
  username: string;
  password: string;
}

/** 登录响应 */
export interface LoginResponse {
  token: string;
  user: UserInfo;
}

/** 用户基本信息 */
export interface UserInfo {
  id: number;
  username: string;
  display_name: string;
  is_super_admin: number; // 0 | 1
  role?: RoleInfo;
  created_at?: string;
}

/** 角色信息 */
export interface RoleInfo {
  level: number;        // 1=超级管理员, 2=管理员, 3=普通用户
  display_name: string;
}

/** Cookie 存储记录 */
export interface CookieRecord {
  id: number;
  platform: 'ctrip' | 'meituan';
  name: string;
  cookie_value: string;
  hotel_id?: number;
  is_valid: number;     // 0 | 1
  last_used_at?: string;
  created_at: string;
  updated_at: string;
}

/** 数据获取任务记录 */
export interface DataFetchTask {
  id: number;
  platform: string;
  task_type: string;
  status: string;       // pending / running / success / failed
  result_data?: unknown;
  error_message?: string;
  created_at: string;
}
