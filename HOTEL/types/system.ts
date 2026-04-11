/* ============================================
   系统配置类型
   ============================================ */

/** 系统配置 */
export interface SystemConfig {
  system_name: string;
  logo_url: string;
  menu_hotel_name: string;
  menu_users_name: string;
  menu_daily_report_name: string;
  menu_monthly_task_name: string;
  menu_report_config_name: string;
  wechat_mini_appid: string;
  wechat_mini_secret: string;
  complaint_mini_page: string;
  complaint_mini_use_scene: string;
}

/** 默认系统配置值 */
export const DEFAULT_SYSTEM_CONFIG: SystemConfig = {
  system_name: '宿析OS',
  logo_url: '',
  menu_hotel_name: '酒店管理',
  menu_users_name: '用户管理',
  menu_daily_report_name: '日报表管理',
  menu_monthly_task_name: '月任务管理',
  menu_report_config_name: '报表配置',
  wechat_mini_appid: '',
  wechat_mini_secret: '',
  complaint_mini_page: 'pages/complaint/index',
  complaint_mini_use_scene: '1',
};

/** 页面路由标识（与 Vue Router 对应） */
export type PageRoute =
  | 'hotels'
  | 'users'
  | 'daily-report'
  | 'monthly-task'
  | 'report-config'
  | 'online-data'
  | 'meituan-ebooking'
  | 'settings';

/** Tab 标识 - 线上数据获取子页面 */
export type OnlineDataTab =
  | 'quick'
  | 'ctrip'
  | 'meituan'
  | 'ctrip-traffic'
  | 'meituan-traffic'
  | 'data'
  | 'cookies';

/** Tab 标识 - 携程 ebooking 子页面 */
export type CtripTab =
  | 'ctrip-ranking'
  | 'ctrip-traffic'
  | 'ctrip-download'
  | 'ctrip-config';

/** Tab 标识 - 美团 ebooking 子页面 */
export type MeituanTab =
  | 'meituan-ranking'
  | 'meituan-traffic'
  | 'meituan-review'
  | 'meituan-download'
  | 'meituan-config';

/** Tab 标识 - 下载中心 */
export type DownloadCenterTab = 'overview' | 'traffic' | 'ai' | 'fetched';
