/* ============================================
   酒店相关业务类型
   ============================================ */

import type { PaginatedData, EnableStatus } from './common';

/** 酒店信息 */
export interface Hotel {
  id: number;
  hotel_name: string;
  short_name?: string;
  ctrip_hotel_id?: string;
  meituan_hotel_id?: string;
  meituan_partner_id?: string;
  meituan_poi_id?: string;
  room_count?: number;
  address?: string;
  contact_phone?: string;
  status: EnableStatus;
  sort_order?: number;
  remark?: string;
  created_at: string;
  updated_at: string;
}

/** 酒店列表项（精简版，用于下拉选择） */
export interface HotelOption {
  id: number;
  hotel_name: string;
}

/** 日报表 */
export interface DailyReport {
  id: number;
  hotel_id: number;
  report_date: string;       // YYYY-MM-DD
  occupancy_rate?: number;
  avg_rate?: number;
  revpar?: number;
  total_revenue?: number;
  room_nights?: number;
  created_at?: string;
}

/** 月任务 */
export interface MonthlyTask {
  id: number;
  task_month: string;        // YYYY-MM
  task_type: string;
  title: string;
  description?: string;
  status: EnableStatus;
  due_date?: string;
  completed_at?: string;
}
