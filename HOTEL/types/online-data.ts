/* ============================================
   线上数据获取相关类型
   ============================================ */

/** ==================== 携程 ebooking ==================== */

/** 携程表单数据 */
export interface CtripForm {
  url: string;
  nodeId: string;
  startDate: string;
  endDate: string;
  cookies: string;
  auth_data: Record<string, unknown>;
}

/** 携程流量表单 */
export interface CtripTrafficForm {
  url: string;
  nodeId: string;
  startDate: string;
  endDate: string;
  cookies: string;
  extraParams: string;
}

/** 携程竞对排名响应 */
export interface CtripRankingResponse {
  code?: number;
  message?: string;
  data?: {
    hotelList?: CtripHotelItem[];
    reportDate?: string;
    [key: string]: unknown;
  };
}

/** 携程酒店条目 */
export interface CtripHotelItem {
  hotelId?: string;
  hotelName?: string;
  rank?: number;
  occupancyRate?: number;
  avgPrice?: number;
  [key: string]: unknown;
}

/** 携程流量数据 */
export interface CtripTrafficData {
  date: string;
  exposureCount?: number;      // 曝光量
  clickCount?: number;         // 点击量
  clickRate?: number;          // 点击率
  bookingCount?: number;       // 下单数
  orderCount?: number;         // 成交单量
  [key: string]: unknown;
}


/** ==================== 美团 ebooking ==================== */

/** 美团表单数据 */
export interface MeituanForm {
  url: string;
  hotelId: string;
  partnerId: string;
  poiId: string;
  rankType: RankType;
  rankTypes: RankType[];
  dateRanges: string[];       // 时间维度多选
  startDate: string;
  endDate: string;
  cookies: string;
  auth_data: Record<string, unknown>;
  hotelRoomCount: string;     // 酒店房量
  competitorRoomCount: string; // 竞争圈总房量
}

/** 美团流量表单 */
export interface MeituanTrafficForm {
  url: string;
  startDate: string;
  endDate: string;
  cookies: string;
  extraParams: string;
}

/** 美团差评获取表单 */
export interface MeituanCommentForm {
  partnerId: string;
  poiId: string;
  cookies: string;
  mtgsig: string;
  startDate: string;
  endDate: string;
  minScore?: number;          // 最低评分筛选
}

/** 美团榜单类型枚举 */
export enum RankType {
  /** 入住率 */
  P_RZ = 'P_RZ',
  /** 销售指数 */
  P_XS = 'P_XS',
  /** 综合指数 */
  P_ZH = 'P_ZH',
  /** 流量曝光 */
  LL_EXPOSE = 'LL_EXPOSE',
}

/** 美团时间维度映射 */
export const MEITUAN_DATE_RANGE_MAP: Record<string, string> = {
  '0': '今日实时',
  '1': '昨日',
  '2': '近7天',
  '3': '近30天',
};

/** 美团竞对排名响应 */
export interface MeituanRankingResponse {
  code: number;
  data?: {
    list?: MeituanRankItem[];
    [key: string]: unknown;
  };
  message?: string;
}

/** 美团排名条目 */
export interface MeituanRankItem {
  hotelName?: string;
  rank?: number;
  value?: number | string;
  [key: string]: unknown;
}


/** ==================== 数据记录 / 下载中心 ==================== */

/** 数据记录列表项 */
export interface DataRecord {
  id: number;
  platform: 'ctrip' | 'meituan';
  data_type: string;          // ranking / traffic / review
  fetch_date: string;
  hotel_id?: number;
  status: string;             // success / failed / pending
  file_path?: string;
  summary?: string;
  error_message?: string;
  created_at: string;
}

/** 下载中心文件 */
export interface DownloadFile {
  id: number;
  filename: string;
  file_size?: number;
  platform: string;
  data_type: string;
  download_count: number;
  created_at: string;
}
