<?php
// 强效定义马来西亚时区
date_default_timezone_set('Asia/Kuala_Lumpur');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 数据库连接配置
$host = 'localhost';
$db   = 'tnb_meter';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// ----------------- 多语言词典 (实现彻底零硬编码) -----------------
$lang = isset($_COOKIE['app_lang']) ? $_COOKIE['app_lang'] : 'en';
if (!in_array($lang, ['en', 'zh', 'ms'])) {
    $lang = 'en';
}

$dictionary = [
    'en' => [
        'app_title' => 'TNB Electricity Meter Monitor',
        'login_title' => 'System Sign In',
        'username' => 'Username',
        'password' => 'Password',
        'btn_login' => 'Sign In',
        'btn_logout' => 'Log Out',
        'select_meter' => 'Select Building / Meter',
        'capture_live' => 'Capture Live Meter Photo',
        'reading_val' => 'Enter Current Meter Reading (kWh)',
        'btn_submit' => 'Submit Daily Reading',
        'user_guide' => 'User Guide',
        'dashboard' => 'Admin Dashboard',
        'settings' => 'System Settings',
        'manage_meters' => 'Manage Meters & Users',
        'meter_name' => 'Meter Code',
        'building' => 'Building Location',
        'limit' => 'Daily Max Limit (kWh)',
        'operator' => 'Submitted By',
        'submitted_at' => 'Submission Time',
        'reading' => 'Reading Value',
        'usage' => 'Usage (24h)',
        'status' => 'Status',
        'photo' => 'Photo Evidence',
        'normal' => 'Normal',
        'exceeded' => 'Exceeded',
        'alert_config' => 'Notification Gateway Configuration',
        'save_btn' => 'Save System Configuration',
        'add_meter' => 'Add New Meter',
        'add_user' => 'Add New Operator',
        'role' => 'Role',
        'admin' => 'Admin',
        'user' => 'Operator',
        'success_saved' => 'Configurations updated successfully.',
        'success_submitted' => 'Meter reading recorded successfully.',
        'err_limit_exceeded' => 'Alert! Daily limit of {meter} has been exceeded by {diff} kWh.',
        'no_photo_err' => 'Submission Rejected: You must capture a LIVE photo to prove presence.',
        'dup_error' => 'Error: A reading has already been submitted for this meter today.',
        'cron_missing' => 'Warning! No meter reading was submitted for {meter} today ({date}).',
        'guide_title_user' => 'Operator Workflow Guide',
        'guide_body_user' => '1. Select the meter assigned to your location.<br>2. Click "Capture Live Meter Photo". This will trigger your mobile device\'s physical camera. Up-to-date photo is strictly required (photo selection from gallery is disabled).<br>3. Read the display on the physical meter and type the exact digits into the reading input field.<br>4. Click "Submit Daily Reading". The system will automatically record your Malaysia submission date and time.',
        'guide_title_admin' => 'Administrator Control Guide',
        'guide_body_admin' => '1. View live metrics, calculated 24h consumption, and photo uploads on the Dashboard.<br>2. Manage available meters and operator accounts in the management section.<br>3. Toggle between WhatsApp and Telegram integration by specifying active endpoints and access keys under System Settings.<br>4. Setup a system cron job to hit the `cron.php` script daily after the designated cut-off time to auto-notify you about missed submissions.',
        'login_failed' => 'Invalid username or password.',
        'no_photo_placeholder' => 'No photo captured.',
        'got_it' => 'Got it',
        'no_records' => 'No submission records.',
        'go_to_portal' => 'Go to Portal',
        'meter_code_label' => 'Meter ID/Code',
        'building_location_label' => 'Building Name / Location',
        'daily_limit_label' => 'Daily Upper Limit (kWh)',
        'create_meter_btn' => 'Create Meter',
        'reg_user_btn' => 'Register User',
        'missing_deadline_label' => 'Missing Reading Deadline (Malaysia Time)',
        'wa_api_url_label' => 'WhatsApp API Gateway URL',
        'wa_token_label' => 'WhatsApp Instance Token',
        'wa_phone_label' => 'Admin Phone Number (Include Country Code)',
        'tg_token_label' => 'Telegram Bot Token',
        'tg_chat_id_label' => 'Telegram Chat ID (Admin)',
        'provider' => 'Notification Provider',
        'view_link' => 'View',
        'err_unauthorized' => 'Unauthorized access.',
        'delete_btn' => 'Delete',
        'prev_page' => 'Previous',
        'next_page' => 'Next',
        'confirm_delete' => 'Are you sure you want to delete this item? This action cannot be undone.',
        'meters_title' => 'Registered Meters List',
        'users_title' => 'System Users List',
        'no_meters' => 'No registered meters found.',
        'no_users' => 'No system users found.',
        'page_info' => 'Page {current} of {total}',
        // 解决硬编码与新增修改功能翻译
        'logged_in_status' => 'Logged In',
        'edit_btn' => 'Edit',
        'edit_meter_title' => 'Modify Meter Details',
        'edit_user_title' => 'Modify System User Details',
        'pw_placeholder_edit' => 'Leave blank to keep current password',
        'btn_save_changes' => 'Save Changes',
        'btn_cancel' => 'Cancel'
    ],
    'zh' => [
        'app_title' => 'TNB 智能电表监控系统',
        'login_title' => '系统登录',
        'username' => '用户名',
        'password' => '登录密码',
        'btn_login' => '立即登录',
        'btn_logout' => '退出系统',
        'select_meter' => '选择对应大楼 / 电表',
        'capture_live' => '现场实时拍照（防作弊）',
        'reading_val' => '手动输入当前电表读数 (kWh)',
        'btn_submit' => '提交当日电表读数',
        'user_guide' => '使用指南',
        'dashboard' => '管理员控制台',
        'settings' => '系统预警设置',
        'manage_meters' => '电表与用户管理',
        'meter_name' => '电表编号',
        'building' => '大楼/位置',
        'limit' => '每日用电上限 (kWh)',
        'operator' => '提交人',
        'submitted_at' => '提交时间',
        'reading' => '电表读数',
        'usage' => '24小时用电',
        'status' => '状态',
        'photo' => '照片证据',
        'normal' => '正常',
        'exceeded' => '超出预警',
        'alert_config' => '消息通道网关设置',
        'save_btn' => '保存系统配置',
        'add_meter' => '添加新电表',
        'add_user' => '添加新操作员',
        'role' => '账户角色',
        'admin' => '管理员',
        'user' => '操作员',
        'success_saved' => '配置信息已更新成功。',
        'success_submitted' => '今日电表读数已成功记录。',
        'err_limit_exceeded' => '超标警告！电表 {meter} 今天的用电量已超过设定的阈值，超标了 {diff} kWh。',
        'no_photo_err' => '提交失败：必须使用摄像头进行现场拍照，不能上传相册旧照。',
        'dup_error' => '错误：该电表今天已经有提交记录，无法重复提交。',
        'cron_missing' => '未提交提醒：电表 {meter} 今日（{date}）暂无任何读数上报记录。',
        'guide_title_user' => '操作员填报指南',
        'guide_body_user' => '1. 在列表中选择您要上报的建筑物/电表。<br>2. 点击“现场实时拍照”按钮。系统将强制调起手机摄像头，相册选择已被禁用以防止作弊。<br>3. 观察物理电表的显示屏，将当前的数字准确填写在输入框内。<br>4. 点击“提交当日读数”，系统会自动按照马来西亚的标准时间戳保存记录。',
        'guide_title_admin' => '管理员操作指南',
        'guide_body_admin' => '1. 在控制台可查看当天的上报数据，系统会通过昨日的记录自动算出用电增长趋势并审核图片。<br>2. 可以在“电表与用户管理”添加新电表，设置每个表独特的日用上限。<br>3. 在“系统预警设置”自由切换 WhatsApp 或是 Telegram 通道，填写相关 Token 后点击测试。<br>4. 推荐将 `cron.php` 挂载在服务器每天定时任务（例如每天 18:00），以自动催报。',
        'login_failed' => '用户名或密码无效。',
        'no_photo_placeholder' => '未拍摄任何电表照片。',
        'got_it' => '我知道了',
        'no_records' => '暂无任何抄表历史记录。',
        'go_to_portal' => '进入填报门户',
        'meter_code_label' => '电表编码/ID',
        'building_location_label' => '大楼名称/具体位置',
        'daily_limit_label' => '每日用电上限阈值 (kWh)',
        'create_meter_btn' => '创建新电表',
        'reg_user_btn' => '注册操作员账户',
        'missing_deadline_label' => '每日抄表截止时间 (马来西亚时间)',
        'wa_api_url_label' => 'WhatsApp API 网关地址 (URL)',
        'wa_token_label' => 'WhatsApp 实例令牌 (Token)',
        'wa_phone_label' => '管理员手机号 (含国家代码)',
        'tg_token_label' => 'Telegram 机器人 Token',
        'tg_chat_id_label' => 'Telegram 接收人 Chat ID',
        'provider' => '选择自动推送通道',
        'view_link' => '查看原图',
        'err_unauthorized' => '未经授权的访问。',
        'delete_btn' => '删除',
        'prev_page' => '上一页',
        'next_page' => '下一页',
        'confirm_delete' => '您确定要删除此条目吗？该操作将无法恢复。',
        'meters_title' => '已登记电表列表',
        'users_title' => '系统账号列表',
        'no_meters' => '暂无已登记的电表。',
        'no_users' => '暂无登记的系统用户。',
        'page_info' => '第 {current} 页 / 共 {total} 页',
        // 解决硬编码与新增修改功能翻译
        'logged_in_status' => '当前登录中',
        'edit_btn' => '编辑',
        'edit_meter_title' => '编辑电表参数信息',
        'edit_user_title' => '修改操作员账号信息',
        'pw_placeholder_edit' => '若不修改密码，请将此输入框留空',
        'btn_save_changes' => '保存修改内容',
        'btn_cancel' => '取消'
    ],
    'ms' => [
        'app_title' => 'Sistem Pemantauan Meter Elektrik TNB',
        'login_title' => 'Log Masuk Sistem',
        'username' => 'Nama Pengguna',
        'password' => 'Kata Laluan',
        'btn_login' => 'Log Masuk',
        'btn_logout' => 'Log Keluar',
        'select_meter' => 'Pilih Bangunan / Meter',
        'capture_live' => 'Ambil Foto Meter Live',
        'reading_val' => 'Masukkan Bacaan Meter Semasa (kWh)',
        'btn_submit' => 'Hantar Bacaan Harian',
        'user_guide' => 'Panduan Pengguna',
        'dashboard' => 'Papan Pemuka Pentadbir',
        'settings' => 'Konfigurasi Sistem',
        'manage_meters' => 'Urus Meter & Pengguna',
        'meter_name' => 'Kod Meter',
        'building' => 'Lokasi Bangunan',
        'limit' => 'Had Maksimum Harian (kWh)',
        'operator' => 'Dihantar Oleh',
        'submitted_at' => 'Waktu Penghantaran',
        'reading' => 'Nilai Bacaan',
        'usage' => 'Penggunaan (24j)',
        'status' => 'Status',
        'photo' => 'Bukti Foto',
        'normal' => 'Normal',
        'exceeded' => 'Melebihi Had',
        'alert_config' => 'Konfigurasi Gateway Notifikasi',
        'save_btn' => 'Simpan Konfigurasi',
        'add_meter' => 'Tambah Meter Baru',
        'add_user' => 'Tambah Operator Baru',
        'role' => 'Peranan',
        'admin' => 'Admin',
        'user' => 'Operator',
        'success_saved' => 'Konfigurasi berjaya dikemas kini.',
        'success_submitted' => 'Bacaan meter berjaya disimpan.',
        'err_limit_exceeded' => 'Amaran! Penggunaan harian meter {meter} telah melebihi had sebanyak {diff} kWh.',
        'no_photo_err' => 'Penghantaran Ditolak: Anda mesti mengambil foto LIVE untuk membantikan kehadiran.',
        'dup_error' => 'Ralat: Bacaan meter ini telah dihantar untuk hari ini.',
        'cron_missing' => 'Peringatan! Tiada bacaan meter dihantar untuk {meter} hari ini ({date}).',
        'guide_title_user' => 'Panduan Kerja Operator',
        'guide_body_user' => '1. Pilih meter bangunan yang ditetapkan.<br>2. Klik "Ambil Foto Meter Live". Kamera telefon anda akan diaktifkan secara automatik (fungsi galeri disekat).<br>3. Baca skrin meter fizikal dan masukkan digit bacaan yang betul.<br>4. Klik "Hantar Bacaan Harian". Sistem akan merekodkan tarikh dan masa Malaysia secara automatik.',
        'guide_title_admin' => 'Panduan Kawalan Pentadbir',
        'guide_body_admin' => '1. Semak bacaan harian, pengiraan penggunaan 24 jam dan bukti foto di Papan Pemuka.<br>2. Urus maklumat meter dan akaun operator di bahagian pengurusan.<br>3. Laraskan pilihan gateway antara WhatsApp atau Telegram di bawah Konfigurasi Notifikasi.<br>4. Sediakan tugas cron harian untuk fail `cron.php` selepas waktu tutup harian bagi menghantar notifikasi peringatan.',
        'login_failed' => 'Nama pengguna atau kata laluan tidak sah.',
        'no_photo_placeholder' => 'Tiada foto meter diambil.',
        'got_it' => 'Faham',
        'no_records' => 'Tiada rekod penghantaran dijumpai.',
        'go_to_portal' => 'Pergi ke Portal',
        'meter_code_label' => 'ID/Kod Meter',
        'building_location_label' => 'Nama Bangunan / Lokasi',
        'daily_limit_label' => 'Had Maksimum Harian (kWh)',
        'create_meter_btn' => 'Cipta Meter Baru',
        'reg_user_btn' => 'Daftar Akaun Operator',
        'missing_deadline_label' => 'Had Masa Penghantaran Harian (Waktu Malaysia)',
        'wa_api_url_label' => 'URL Gateway API WhatsApp',
        'wa_token_label' => 'Token Instance WhatsApp',
        'wa_phone_label' => 'Nombor Telefon Admin (Sertakan Kod Negara)',
        'tg_token_label' => 'Token Bot Telegram',
        'tg_chat_id_label' => 'Chat ID Telegram (Admin)',
        'provider' => 'Gateway Penghantaran Notifikasi',
        'view_link' => 'Papar Gambar',
        'err_unauthorized' => 'Akses tidak sah.',
        'delete_btn' => 'Padam',
        'prev_page' => 'Terdahulu',
        'next_page' => 'Seterusnya',
        'confirm_delete' => 'Adakah anda pasti mahu memadam item ini? Tindakan ini tidak boleh diubah.',
        'meters_title' => 'Senarai Meter Berdaftar',
        'users_title' => 'Senarai Pengguna Sistem',
        'no_meters' => 'Tiada meter berdaftar dijumpai.',
        'no_users' => 'Tiada pengguna berdaftar dijumpai.',
        'page_info' => 'Halaman {current} daripada {total}',
        // 解决硬编码与新增修改功能翻译
        'logged_in_status' => 'Log Masuk Semasa',
        'edit_btn' => 'Ubah',
        'edit_meter_title' => 'Ubah Suai Parameter Meter',
        'edit_user_title' => 'Ubah Suai Akaun Pengguna',
        'pw_placeholder_edit' => 'Biarkan kosong jika tidak mahu menukar kata laluan',
        'btn_save_changes' => 'Simpan Perubahan',
        'btn_cancel' => 'Batal'
    ]
];

function __($key) {
    global $dictionary, $lang;
    return isset($dictionary[$lang][$key]) ? $dictionary[$lang][$key] : $key;
}

// ----------------- 输出动态JS多语言字典 -----------------
function get_js_translations_json() {
    global $dictionary, $lang;
    return json_encode($dictionary[$lang]);
}

// ----------------- 系统自定义参数读取 -----------------
$system_configs = [];
$cfg_stmt = $pdo->query("SELECT * FROM configs");
while ($row = $cfg_stmt->fetch()) {
    $system_configs[$row['cfg_key']] = $row['cfg_value'];
}

// ----------------- 消息推送核心函数 (多通道支持) -----------------
function send_alert_notification($message) {
    global $system_configs;
    $provider = $system_configs['notification_provider'] ?? 'telegram';
    
    if ($provider === 'whatsapp') {
        $api_url = $system_configs['whatsapp_api_url'] ?? '';
        $token = $system_configs['whatsapp_token'] ?? '';
        $to_phone = $system_configs['admin_phone'] ?? '';
        
        if (empty($api_url) || empty($token) || empty($to_phone)) {
            return false;
        }
        
        $post_data = [
            'token' => $token,
            'to' => $to_phone,
            'body' => $message
        ];
        
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        return $res;
        
    } elseif ($provider === 'telegram') {
        $bot_token = $system_configs['telegram_bot_token'] ?? '';
        $chat_id = $system_configs['telegram_chat_id'] ?? '';
        
        if (empty($bot_token) || empty($chat_id)) {
            return false;
        }
        
        $api_url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
        $post_data = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];
        
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        return $res;
    }
    return false;
}

// ----------------- 主题初始化设置 -----------------
$theme = isset($_COOKIE['app_theme']) ? $_COOKIE['app_theme'] : 'light';
if (!in_array($theme, ['light', 'dark'])) {
    $theme = 'light';
}