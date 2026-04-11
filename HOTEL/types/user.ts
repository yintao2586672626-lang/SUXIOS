/* ============================================
   用户/权限相关类型
   ============================================ */

import type { PaginatedData, EnableStatus } from './common';

/** 用户完整信息 */
export interface User {
  id: number;
  username: string;
  password?: string;          // 仅创建/修改时使用
  display_name: string;
  email?: string;
  phone?: string;
  role_level: number;         // 1=超级管理员, 2=管理员, 3=普通用户
  is_super_admin: number;     // 0 | 1
  status: EnableStatus;
  last_login_at?: string;
  created_at: string;
  updated_at: string;

  /** 前端展示用角色信息 */
  role?: {
    level: number;
    display_name: string;
  };
}

/** 用户列表查询参数 */
export interface UserListParams {
  page: number;
  pageSize: number;
  keyword?: string;
  status?: EnableStatus;
}
