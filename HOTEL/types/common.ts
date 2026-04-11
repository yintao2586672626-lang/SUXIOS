/* ============================================
   通用工具类型 - 分页、状态码、通用响应
   ============================================ */

/** API 统一响应结构 */
export interface ApiResponse<T = unknown> {
  code: number;
  message: string;
  data: T;
}

/** 分页请求参数 */
export interface PaginationParams {
  page: number;
  pageSize: number;
  keyword?: string;
  status?: string | number;
  [key: string]: unknown;
}

/** 分页响应数据 */
export interface PaginatedData<T> {
  list: T[];
  total: number;
  page: number;
  pageSize: number;
  totalPages?: number;
}

/** 状态码枚举 */
export enum StatusCode {
  SUCCESS = 200,
  CREATED = 201,
  BAD_REQUEST = 400,
  UNAUTHORIZED = 401,
  FORBIDDEN = 403,
  NOT_FOUND = 404,
  SERVER_ERROR = 500,
}

/** 通用状态（启用/禁用） */
export type EnableStatus = '0' | '1' | 0 | 1;

/** 日期范围 */
export interface DateRange {
  startDate: string; // YYYY-MM-DD
  endDate: string;   // YYYY-MM-DD
}

/** 表格列定义 */
export interface TableColumn {
  key: string;
  label: string;
  sortable?: boolean;
  width?: string;
}
