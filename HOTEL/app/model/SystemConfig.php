<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 系统配置模型
 */
class SystemConfig extends Model
{
    // 表名
    protected $name = 'system_config';

    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 配置键常量 - 基础设置
    const KEY_SYSTEM_NAME = 'system_name';
    const KEY_LOGO_URL = 'logo_url';
    const KEY_FAVICON_URL = 'favicon_url';
    const KEY_SYSTEM_DESCRIPTION = 'system_description';
    const KEY_SYSTEM_KEYWORDS = 'system_keywords';
    
    // 菜单配置
    const KEY_MENU_HOTEL = 'menu_hotel_name';
    const KEY_MENU_USERS = 'menu_users_name';
    const KEY_MENU_DAILY_REPORT = 'menu_daily_report_name';
    const KEY_MENU_MONTHLY_TASK = 'menu_monthly_task_name';
    const KEY_MENU_REPORT_CONFIG = 'menu_report_config_name';
    const KEY_MENU_COMPASS = 'menu_compass_name';
    const KEY_MENU_ONLINE_DATA = 'menu_online_data_name';
    
    // 显示设置
    const KEY_THEME = 'theme';
    const KEY_PRIMARY_COLOR = 'primary_color';
    const KEY_DATE_FORMAT = 'date_format';
    const KEY_TIME_FORMAT = 'time_format';
    const KEY_PAGE_SIZE_OPTIONS = 'page_size_options';
    const KEY_DEFAULT_PAGE_SIZE = 'default_page_size';
    
    // 功能开关
    const KEY_ENABLE_REGISTRATION = 'enable_registration';
    const KEY_ENABLE_LOGIN_LOG = 'enable_login_log';
    const KEY_ENABLE_OPERATION_LOG = 'enable_operation_log';
    const KEY_ENABLE_DATA_BACKUP = 'enable_data_backup';
    const KEY_ENABLE_WECHAT_MINI = 'enable_wechat_mini';
    const KEY_ENABLE_ONLINE_DATA = 'enable_online_data';
    
    // 微信小程序配置
    const KEY_WECHAT_MINI_APPID = 'wechat_mini_appid';
    const KEY_WECHAT_MINI_SECRET = 'wechat_mini_secret';
    const KEY_COMPLAINT_MINI_PAGE = 'complaint_mini_page';
    const KEY_COMPLAINT_MINI_USE_SCENE = 'complaint_mini_use_scene';
    
    // 安全设置
    const KEY_LOGIN_MAX_ATTEMPTS = 'login_max_attempts';
    const KEY_LOGIN_LOCKOUT_DURATION = 'login_lockout_duration';
    const KEY_SESSION_TIMEOUT = 'session_timeout';
    const KEY_PASSWORD_MIN_LENGTH = 'password_min_length';
    const KEY_PASSWORD_REQUIRE_SPECIAL = 'password_require_special';
    
    // 通知设置
    const KEY_NOTIFY_EMAIL_ENABLED = 'notify_email_enabled';
    const KEY_NOTIFY_EMAIL_SERVER = 'notify_email_server';
    const KEY_NOTIFY_EMAIL_PORT = 'notify_email_port';
    const KEY_NOTIFY_EMAIL_USER = 'notify_email_user';
    const KEY_NOTIFY_EMAIL_PASS = 'notify_email_pass';
    const KEY_NOTIFY_DAILY_REPORT = 'notify_daily_report';
    const KEY_NOTIFY_MONTHLY_TASK = 'notify_monthly_task';

    /**
     * 获取配置值
     */
    public static function getValue(string $key, $default = null)
    {
        $config = self::where('config_key', $key)->find();
        return $config ? $config->config_value : $default;
    }

    /**
     * 设置配置值
     */
    public static function setValue(string $key, $value, string $description = ''): bool
    {
        $config = self::where('config_key', $key)->find();
        if ($config) {
            $config->config_value = $value;
            return $config->save();
        } else {
            $config = new self();
            $config->config_key = $key;
            $config->config_value = $value;
            $config->description = $description;
            return $config->save();
        }
    }

    /**
     * 获取所有配置
     */
    public static function getAllConfigs(): array
    {
        $configs = self::select();
        $result = [];
        foreach ($configs as $config) {
            $result[$config->config_key] = $config->config_value;
        }
        return $result;
    }

    /**
     * 获取默认配置
     */
    public static function getDefaultConfigs(): array
    {
        return [
            // 基础设置
            self::KEY_SYSTEM_NAME => '宿析OS',
            self::KEY_SYSTEM_DESCRIPTION => '深度数据分析赋能酒店收益与决策',
            self::KEY_LOGO_URL => '',
            self::KEY_FAVICON_URL => '',
            self::KEY_SYSTEM_DESCRIPTION => '深度数据分析赋能酒店收益与决策',
            self::KEY_SYSTEM_KEYWORDS => '酒店管理,收益分析,数据分析',
            
            // 菜单配置
            self::KEY_MENU_HOTEL => '酒店管理',
            self::KEY_MENU_USERS => '用户管理',
            self::KEY_MENU_DAILY_REPORT => '日报表管理',
            self::KEY_MENU_MONTHLY_TASK => '月任务管理',
            self::KEY_MENU_REPORT_CONFIG => '报表配置',
            self::KEY_MENU_COMPASS => '罗盘',
            self::KEY_MENU_ONLINE_DATA => '竞对价格监控',
            
            // 显示设置
            self::KEY_THEME => 'light',
            self::KEY_PRIMARY_COLOR => '#3B82F6',
            self::KEY_DATE_FORMAT => 'Y-m-d',
            self::KEY_TIME_FORMAT => 'H:i:s',
            self::KEY_PAGE_SIZE_OPTIONS => '10,20,50,100',
            self::KEY_DEFAULT_PAGE_SIZE => '20',
            
            // 功能开关
            self::KEY_ENABLE_REGISTRATION => '0',
            self::KEY_ENABLE_LOGIN_LOG => '1',
            self::KEY_ENABLE_OPERATION_LOG => '1',
            self::KEY_ENABLE_DATA_BACKUP => '1',
            self::KEY_ENABLE_WECHAT_MINI => '0',
            self::KEY_ENABLE_ONLINE_DATA => '1',
            
            // 微信小程序配置
            self::KEY_WECHAT_MINI_APPID => '',
            self::KEY_WECHAT_MINI_SECRET => '',
            self::KEY_COMPLAINT_MINI_PAGE => 'pages/complaint/index',
            self::KEY_COMPLAINT_MINI_USE_SCENE => '1',
            
            // 安全设置
            self::KEY_LOGIN_MAX_ATTEMPTS => '10',
            self::KEY_LOGIN_LOCKOUT_DURATION => '1',
            self::KEY_SESSION_TIMEOUT => '14400',
            self::KEY_PASSWORD_MIN_LENGTH => '0',
            self::KEY_PASSWORD_REQUIRE_SPECIAL => '0',
            
            // 通知设置
            self::KEY_NOTIFY_EMAIL_ENABLED => '0',
            self::KEY_NOTIFY_EMAIL_SERVER => '',
            self::KEY_NOTIFY_EMAIL_PORT => '587',
            self::KEY_NOTIFY_EMAIL_USER => '',
            self::KEY_NOTIFY_EMAIL_PASS => '',
            self::KEY_NOTIFY_DAILY_REPORT => '0',
            self::KEY_NOTIFY_MONTHLY_TASK => '0',
        ];
    }
    
    /**
     * 获取配置分组
     */
    public static function getConfigGroups(): array
    {
        return [
            'basic' => [
                'title' => '基础设置',
                'icon' => 'fas fa-cog',
                'keys' => [
                    self::KEY_SYSTEM_NAME,
                    self::KEY_LOGO_URL,
                    self::KEY_FAVICON_URL,
                    self::KEY_SYSTEM_DESCRIPTION,
                    self::KEY_SYSTEM_KEYWORDS,
                ],
            ],
            'menu' => [
                'title' => '菜单配置',
                'icon' => 'fas fa-bars',
                'keys' => [
                    self::KEY_MENU_HOTEL,
                    self::KEY_MENU_USERS,
                    self::KEY_MENU_DAILY_REPORT,
                    self::KEY_MENU_MONTHLY_TASK,
                    self::KEY_MENU_REPORT_CONFIG,
                    self::KEY_MENU_COMPASS,
                    self::KEY_MENU_ONLINE_DATA,
                ],
            ],
            'display' => [
                'title' => '显示设置',
                'icon' => 'fas fa-palette',
                'keys' => [
                    self::KEY_THEME,
                    self::KEY_PRIMARY_COLOR,
                    self::KEY_DATE_FORMAT,
                    self::KEY_TIME_FORMAT,
                    self::KEY_PAGE_SIZE_OPTIONS,
                    self::KEY_DEFAULT_PAGE_SIZE,
                ],
            ],
            'feature' => [
                'title' => '功能开关',
                'icon' => 'fas fa-toggle-on',
                'keys' => [
                    self::KEY_ENABLE_REGISTRATION,
                    self::KEY_ENABLE_LOGIN_LOG,
                    self::KEY_ENABLE_OPERATION_LOG,
                    self::KEY_ENABLE_DATA_BACKUP,
                    self::KEY_ENABLE_WECHAT_MINI,
                    self::KEY_ENABLE_ONLINE_DATA,
                ],
            ],
            'wechat' => [
                'title' => '微信小程序',
                'icon' => 'fab fa-weixin',
                'keys' => [
                    self::KEY_WECHAT_MINI_APPID,
                    self::KEY_WECHAT_MINI_SECRET,
                    self::KEY_COMPLAINT_MINI_PAGE,
                    self::KEY_COMPLAINT_MINI_USE_SCENE,
                ],
            ],
            'security' => [
                'title' => '安全设置',
                'icon' => 'fas fa-shield-alt',
                'keys' => [
                    self::KEY_LOGIN_MAX_ATTEMPTS,
                    self::KEY_LOGIN_LOCKOUT_DURATION,
                    self::KEY_SESSION_TIMEOUT,
                    self::KEY_PASSWORD_MIN_LENGTH,
                    self::KEY_PASSWORD_REQUIRE_SPECIAL,
                ],
            ],
            'notification' => [
                'title' => '通知设置',
                'icon' => 'fas fa-bell',
                'keys' => [
                    self::KEY_NOTIFY_EMAIL_ENABLED,
                    self::KEY_NOTIFY_EMAIL_SERVER,
                    self::KEY_NOTIFY_EMAIL_PORT,
                    self::KEY_NOTIFY_EMAIL_USER,
                    self::KEY_NOTIFY_EMAIL_PASS,
                    self::KEY_NOTIFY_DAILY_REPORT,
                    self::KEY_NOTIFY_MONTHLY_TASK,
                ],
            ],
        ];
    }
    
    /**
     * 获取配置项描述
     */
    public static function getConfigDescriptions(): array
    {
        return [
            // 基础设置
            self::KEY_SYSTEM_NAME => '系统名称',
            self::KEY_LOGO_URL => '系统Logo URL',
            self::KEY_FAVICON_URL => '浏览器图标URL',
            self::KEY_SYSTEM_DESCRIPTION => '系统描述',
            self::KEY_SYSTEM_KEYWORDS => '系统关键词',
            
            // 菜单配置
            self::KEY_MENU_HOTEL => '酒店管理菜单名',
            self::KEY_MENU_USERS => '用户管理菜单名',
            self::KEY_MENU_DAILY_REPORT => '日报表管理菜单名',
            self::KEY_MENU_MONTHLY_TASK => '月任务管理菜单名',
            self::KEY_MENU_REPORT_CONFIG => '报表配置菜单名',
            self::KEY_MENU_COMPASS => '罗盘菜单名',
            self::KEY_MENU_ONLINE_DATA => '竞对价格监控菜单名',
            
            // 显示设置
            self::KEY_THEME => '主题风格',
            self::KEY_PRIMARY_COLOR => '主题色',
            self::KEY_DATE_FORMAT => '日期格式',
            self::KEY_TIME_FORMAT => '时间格式',
            self::KEY_PAGE_SIZE_OPTIONS => '分页选项',
            self::KEY_DEFAULT_PAGE_SIZE => '默认分页大小',
            
            // 功能开关
            self::KEY_ENABLE_REGISTRATION => '启用用户注册',
            self::KEY_ENABLE_LOGIN_LOG => '启用登录日志',
            self::KEY_ENABLE_OPERATION_LOG => '启用操作日志',
            self::KEY_ENABLE_DATA_BACKUP => '启用数据备份',
            self::KEY_ENABLE_WECHAT_MINI => '启用微信小程序',
            self::KEY_ENABLE_ONLINE_DATA => '启用线上数据',
            
            // 微信小程序
            self::KEY_WECHAT_MINI_APPID => '微信小程序AppID',
            self::KEY_WECHAT_MINI_SECRET => '微信小程序AppSecret',
            self::KEY_COMPLAINT_MINI_PAGE => '吐槽码小程序页面路径',
            self::KEY_COMPLAINT_MINI_USE_SCENE => '吐槽码小程序使用scene',
            
            // 安全设置
            self::KEY_LOGIN_MAX_ATTEMPTS => '登录最大尝试次数',
            self::KEY_LOGIN_LOCKOUT_DURATION => '登录锁定时间(分钟)',
            self::KEY_SESSION_TIMEOUT => '会话超时时间(分钟)',
            self::KEY_PASSWORD_MIN_LENGTH => '密码最小长度',
            self::KEY_PASSWORD_REQUIRE_SPECIAL => '密码要求特殊字符',
            
            // 通知设置
            self::KEY_NOTIFY_EMAIL_ENABLED => '启用邮件通知',
            self::KEY_NOTIFY_EMAIL_SERVER => 'SMTP服务器',
            self::KEY_NOTIFY_EMAIL_PORT => 'SMTP端口',
            self::KEY_NOTIFY_EMAIL_USER => 'SMTP用户名',
            self::KEY_NOTIFY_EMAIL_PASS => 'SMTP密码',
            self::KEY_NOTIFY_DAILY_REPORT => '日报表提交通知',
            self::KEY_NOTIFY_MONTHLY_TASK => '月任务提交通知',
        ];
    }
}
