<?php
require_once 'db.php';

// 安全守卫：非管理员禁止访问
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// 处理语言和主题切换的POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'set_lang') {
        setcookie('app_lang', $_POST['lang_val'], time() + (3600 * 24 * 30), "/");
        header("Location: admin.php");
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'set_theme') {
        setcookie('app_theme', $_POST['theme_val'], time() + (3600 * 24 * 30), "/");
        header("Location: admin.php");
        exit;
    }
}

// 写入新的预警配置参数
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    foreach ($_POST['config'] as $key => $val) {
        $stmt = $pdo->prepare("INSERT INTO configs (cfg_key, cfg_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cfg_value = ?");
        $stmt->execute([$key, $val, $val]);
    }
    header("Location: admin.php?success=1");
    exit;
}

// 添加电表
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_meter'])) {
    $m_name = trim($_POST['m_name']);
    $b_name = trim($_POST['b_name']);
    $limit_val = (double)$_POST['limit_val'];
    
    $stmt = $pdo->prepare("INSERT INTO meters (meter_name, building_name, usage_limit) VALUES (?, ?, ?)");
    $stmt->execute([$m_name, $b_name, $limit_val]);
    header("Location: admin.php");
    exit;
}

// 添加新操作员
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user_op'])) {
    $uname = trim($_POST['uname']);
    $upass = password_hash($_POST['upass'], PASSWORD_DEFAULT);
    $urole = $_POST['urole'];
    
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
    $stmt->execute([$uname, $upass, $urole]);
    header("Location: admin.php");
    exit;
}

// 获取历史抄表记录，联合查询用户和电表数据
$readings = $pdo->query("
    SELECT r.*, m.meter_name, m.building_name, m.usage_limit, u.username 
    FROM readings r
    JOIN meters m ON r.meter_id = m.id
    JOIN users u ON r.user_id = u.id
    ORDER BY r.submitted_at DESC
")->fetchAll();

// 获取当前所有的电表
$meters = $pdo->query("SELECT * FROM meters ORDER BY meter_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" class="<?php echo ($theme === 'dark') ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('dashboard'); ?> - <?php echo __('app_title'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100 min-h-screen transition-colors duration-200">

    <!-- 顶部状态栏 -->
    <header class="bg-white dark:bg-gray-800 shadow p-4 sticky top-0 z-40">
        <div class="max-w-6xl mx-auto flex flex-wrap items-center justify-between gap-4">
            <h1 class="font-bold text-lg tracking-tight flex items-center gap-2">
                <i data-lucide="shield-check" class="text-purple-500 fill-purple-100"></i>
                <?php echo __('dashboard'); ?>
            </h1>
            
            <div class="flex items-center gap-3">
                <!-- 切换语言 -->
                <form method="POST" class="inline-block">
                    <input type="hidden" name="action" value="set_lang">
                    <select name="lang_val" onchange="this.form.submit()" class="bg-gray-100 dark:bg-gray-700 text-xs rounded border p-1 focus:outline-none">
                        <option value="en" <?php echo ($lang === 'en')?'selected':''; ?>>English</option>
                        <option value="zh" <?php echo ($lang === 'zh')?'selected':''; ?>>中文</option>
                        <option value="ms" <?php echo ($lang === 'ms')?'selected':''; ?>>Bahasa Melayu</option>
                    </select>
                </form>

                <!-- 切换主题 -->
                <form method="POST" class="inline-block">
                    <input type="hidden" name="action" value="set_theme">
                    <button type="submit" name="theme_val" value="<?php echo ($theme==='light')?'dark':'light'; ?>" class="p-1 rounded bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        <?php if($theme === 'light'): ?>
                            <i data-lucide="moon" class="w-4 h-4"></i>
                        <?php else: ?>
                            <i data-lucide="sun" class="w-4 h-4"></i>
                        <?php endif; ?>
                    </button>
                </form>

                <!-- 用户指南 -->
                <button onclick="toggleModal('admin-guide')" class="p-1 rounded bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200 hover:opacity-80 transition flex items-center gap-1 text-xs px-2 font-semibold">
                    <i data-lucide="help-circle" class="w-4 h-4"></i>
                    <?php echo __('user_guide'); ?>
                </button>

                <a href="index.php" class="text-xs bg-gray-200 dark:bg-gray-700 px-3 py-1.5 rounded font-bold hover:opacity-80 transition flex items-center gap-1">
                    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                    <?php echo __('go_to_portal'); ?>
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto p-4 space-y-6">

        <?php if(isset($_GET['success'])): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 mb-4 rounded text-sm flex items-center gap-2">
                <i data-lucide="check-circle" class="w-5 h-5 shrink-0"></i>
                <span><?php echo __('success_saved'); ?></span>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- 左侧列表: 抄表大盘报表 -->
            <section class="lg:col-span-2 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 p-6 overflow-hidden">
                    <h2 class="text-base font-bold mb-4 flex items-center gap-2">
                        <i data-lucide="activity" class="text-purple-500"></i>
                        <?php echo __('dashboard'); ?>
                    </h2>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700 text-xs uppercase text-gray-500 tracking-wider">
                                    <th class="py-3 px-2"><?php echo __('meter_name'); ?></th>
                                    <th class="py-3 px-2"><?php echo __('operator'); ?></th>
                                    <th class="py-3 px-2"><?php echo __('reading'); ?></th>
                                    <th class="py-3 px-2"><?php echo __('usage'); ?></th>
                                    <th class="py-3 px-2"><?php echo __('status'); ?></th>
                                    <th class="py-3 px-2"><?php echo __('photo'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($readings)): ?>
                                    <tr>
                                        <td colspan="6" class="py-4 text-center text-gray-400"><?php echo __('no_records'); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach($readings as $r): 
                                    // 查找昨日对应记录来算出本日用量
                                    $prev_stmt = $pdo->prepare("SELECT reading_value FROM readings WHERE meter_id = ? AND submitted_date < ? ORDER BY submitted_date DESC LIMIT 1");
                                    $prev_stmt->execute([$r['meter_id'], $r['submitted_date']]);
                                    $prev_row = $prev_stmt->fetch();
                                    
                                    $usage_diff = 'N/A';
                                    $is_over = false;
                                    if ($prev_row) {
                                        $usage_diff = (double)$r['reading_value'] - (double)$prev_row['reading_value'];
                                        if ($usage_diff > (double)$r['usage_limit']) {
                                            $is_over = true;
                                        }
                                    }
                                ?>
                                    <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-700/30 transition text-xs">
                                        <td class="py-3 px-2 font-semibold">
                                            <?php echo htmlspecialchars($r['meter_name']); ?>
                                            <span class="block text-[10px] text-gray-400 font-normal"><?php echo htmlspecialchars($r['building_name']); ?></span>
                                        </td>
                                        <td class="py-3 px-2">
                                            <?php echo htmlspecialchars($r['username']); ?>
                                            <span class="block text-[10px] text-gray-400"><?php echo $r['submitted_at']; ?></span>
                                        </td>
                                        <td class="py-3 px-2 font-bold"><?php echo $r['reading_value']; ?> kWh</td>
                                        <td class="py-3 px-2 font-semibold">
                                            <?php echo is_numeric($usage_diff) ? $usage_diff . ' kWh' : 'N/A'; ?>
                                        </td>
                                        <td class="py-3 px-2">
                                            <?php if($is_over): ?>
                                                <span class="bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200 px-2 py-0.5 rounded text-[10px] font-bold">
                                                    ▲ <?php echo __('exceeded'); ?> (+<?php echo $usage_diff - $r['usage_limit']; ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200 px-2 py-0.5 rounded text-[10px] font-bold">
                                                    ✓ <?php echo __('normal'); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-2">
                                            <a href="<?php echo $r['photo_path']; ?>" target="_blank" class="text-blue-500 hover:underline flex items-center gap-1 font-semibold">
                                                <i data-lucide="external-link" class="w-3 h-3"></i> <?php echo __('view_link'); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 数据管理区域：电表管理及用户账号管理 -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <!-- 添加电表 -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 p-6">
                        <h3 class="text-sm font-bold mb-4 flex items-center gap-1.5">
                            <i data-lucide="plus-circle" class="text-green-500"></i>
                            <?php echo __('add_meter'); ?>
                        </h3>
                        <form method="POST" class="space-y-3 text-xs">
                            <input type="hidden" name="add_meter" value="1">
                            <div>
                                <label class="block font-semibold mb-1"><?php echo __('meter_code_label'); ?></label>
                                <input type="text" name="m_name" placeholder="e.g. TNB-M-01" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-purple-500 outline-none">
                            </div>
                            <div>
                                <label class="block font-semibold mb-1"><?php echo __('building_location_label'); ?></label>
                                <input type="text" name="b_name" placeholder="e.g. Level 3 Hub" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-purple-500 outline-none">
                            </div>
                            <div>
                                <label class="block font-semibold mb-1"><?php echo __('daily_limit_label'); ?></label>
                                <input type="number" step="0.1" name="limit_val" value="80.0" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-purple-500 outline-none font-bold">
                            </div>
                            <button type="submit" class="w-full bg-purple-600 text-white p-2 rounded font-bold hover:bg-purple-700 transition">
                                <?php echo __('create_meter_btn'); ?>
                            </button>
                        </form>
                    </div>

                    <!-- 添加操作员 -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 p-6">
                        <h3 class="text-sm font-bold mb-4 flex items-center gap-1.5">
                            <i data-lucide="user-plus" class="text-blue-500"></i>
                            <?php echo __('add_user'); ?>
                        </h3>
                        <form method="POST" class="space-y-3 text-xs">
                            <input type="hidden" name="add_user_op" value="1">
                            <div>
                                <label class="block font-semibold mb-1"><?php echo __('username'); ?></label>
                                <input type="text" name="uname" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-purple-500 outline-none">
                            </div>
                            <div>
                                <label class="block font-semibold mb-1"><?php echo __('password'); ?></label>
                                <input type="password" name="upass" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-purple-500 outline-none">
                            </div>
                            <div>
                                <label class="block font-semibold mb-1"><?php echo __('role'); ?></label>
                                <select name="urole" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-purple-500 outline-none">
                                    <option value="user"><?php echo __('user'); ?></option>
                                    <option value="admin"><?php echo __('admin'); ?></option>
                                </select>
                            </div>
                            <button type="submit" class="w-full bg-purple-600 text-white p-2 rounded font-bold hover:bg-purple-700 transition">
                                <?php echo __('reg_user_btn'); ?>
                            </button>
                        </form>
                    </div>

                </div>
            </section>

            <!-- 右侧配置区域 -->
            <section class="space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 p-6">
                    <h2 class="text-base font-bold mb-4 flex items-center gap-2">
                        <i data-lucide="settings-2" class="text-blue-500"></i>
                        <?php echo __('alert_config'); ?>
                    </h2>
                    
                    <form method="POST" class="space-y-4 text-xs">
                        <input type="hidden" name="save_settings" value="1">
                        
                        <!-- 选择通道 -->
                        <div>
                            <label class="block font-semibold mb-1.5"><?php echo __('provider'); ?></label>
                            <select name="config[notification_provider]" id="notifProvider" onchange="toggleConfigFields()" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none font-semibold">
                                <option value="telegram" <?php echo (($system_configs['notification_provider'] ?? 'telegram') === 'telegram') ? 'selected':''; ?>>Telegram Bot</option>
                                <option value="whatsapp" <?php echo (($system_configs['notification_provider'] ?? '') === 'whatsapp') ? 'selected':''; ?>>WhatsApp Gateway</option>
                            </select>
                        </div>

                        <!-- 漏抄提醒截止时间 -->
                        <div>
                            <label class="block font-semibold mb-1.5"><?php echo __('missing_deadline_label'); ?></label>
                            <input type="time" name="config[submission_deadline]" value="<?php echo htmlspecialchars($system_configs['submission_deadline'] ?? '18:00'); ?>" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none">
                        </div>

                        <!-- Telegram 专属参数组 -->
                        <div id="tgFields" class="space-y-3">
                            <div class="border-t border-gray-100 dark:border-gray-700 pt-3">
                                <label class="block font-semibold mb-1 text-blue-500"><?php echo __('tg_token_label'); ?></label>
                                <input type="password" name="config[telegram_bot_token]" value="<?php echo htmlspecialchars($system_configs['telegram_bot_token'] ?? ''); ?>" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block font-semibold mb-1 text-blue-500"><?php echo __('tg_chat_id_label'); ?></label>
                                <input type="text" name="config[telegram_chat_id]" value="<?php echo htmlspecialchars($system_configs['telegram_chat_id'] ?? ''); ?>" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none">
                            </div>
                        </div>

                        <!-- WhatsApp 专属参数组 -->
                        <div id="waFields" class="space-y-3 hidden">
                            <div class="border-t border-gray-100 dark:border-gray-700 pt-3">
                                <label class="block font-semibold mb-1 text-green-600"><?php echo __('wa_api_url_label'); ?></label>
                                <input type="text" name="config[whatsapp_api_url]" value="<?php echo htmlspecialchars($system_configs['whatsapp_api_url'] ?? ''); ?>" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block font-semibold mb-1 text-green-600"><?php echo __('wa_token_label'); ?></label>
                                <input type="password" name="config[whatsapp_token]" value="<?php echo htmlspecialchars($system_configs['whatsapp_token'] ?? ''); ?>" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none">
                            </div>
                            <div>
                                <label class="block font-semibold mb-1 text-green-600"><?php echo __('wa_phone_label'); ?></label>
                                <input type="text" name="config[admin_phone]" value="<?php echo htmlspecialchars($system_configs['admin_phone'] ?? ''); ?>" placeholder="e.g. 60123456789" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none">
                            </div>
                        </div>

                        <button type="submit" class="w-full bg-blue-600 text-white p-2.5 rounded font-bold hover:bg-blue-700 transition flex items-center justify-center gap-2">
                            <i data-lucide="save" class="w-4 h-4"></i>
                            <?php echo __('save_btn'); ?>
                        </button>
                    </form>
                </div>
            </section>

        </div>

    </main>

    <!-- 管理员指南弹窗 -->
    <div id="admin-guide" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full shadow-2xl p-6 relative border border-gray-100 dark:border-gray-700">
            <button onclick="toggleModal('admin-guide')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <i data-lucide="shield" class="text-purple-500"></i>
                <?php echo __('guide_title_admin'); ?>
            </h3>
            <div class="text-sm space-y-2 leading-relaxed text-gray-600 dark:text-gray-300">
                <?php echo __('guide_body_admin'); ?>
            </div>
            <button onclick="toggleModal('admin-guide')" class="mt-6 w-full bg-purple-600 text-white p-2 rounded font-semibold hover:bg-purple-700 transition">
                <?php echo __('got_it'); ?>
            </button>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function toggleModal(id) {
            const modal = document.getElementById(id);
            modal.classList.toggle('hidden');
        }

        // 动态隐藏/显示配置字段
        function toggleConfigFields() {
            const provider = document.getElementById('notifProvider').value;
            const tg = document.getElementById('tgFields');
            const wa = document.getElementById('waFields');

            if (provider === 'telegram') {
                tg.classList.remove('hidden');
                wa.classList.add('hidden');
            } else {
                tg.classList.add('hidden');
                wa.classList.remove('hidden');
            }
        }
        
        // 首次加载初始化视图
        window.onload = function() {
            toggleConfigFields();
        };
    </script>
</body>
</html>