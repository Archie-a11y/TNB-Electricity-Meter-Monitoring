<?php
require_once 'db.php';

// 安全守卫：非管理员禁止访问 [3]
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// ----------------- 读取用户Cookie卡片折叠状态，实现界面记忆还原 -----------------
$collapse_filter   = ($_COOKIE['card_filter_collapsed'] ?? 'false') === 'true';
$collapse_pairing  = ($_COOKIE['card_pairing_collapsed'] ?? 'false') === 'true';
$collapse_meters   = ($_COOKIE['card_meters_collapsed'] ?? 'false') === 'true';
$collapse_users    = ($_COOKIE['card_users_collapsed'] ?? 'false') === 'true';
$collapse_settings = ($_COOKIE['card_settings_collapsed'] ?? 'true') === 'true'; // 网关设置默认合拢

// ----------------- 处理删除/解绑逻辑 -----------------
if (isset($_GET['delete_meter'])) {
    $m_id = (int)$_GET['delete_meter'];
    $stmt = $pdo->prepare("DELETE FROM meters WHERE id = ?");
    $stmt->execute([$m_id]);
    header("Location: admin.php");
    exit;
}

if (isset($_GET['delete_user'])) {
    $u_id = (int)$_GET['delete_user'];
    if ($u_id !== (int)$_SESSION['user_id']) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$u_id]);
    }
    header("Location: admin.php");
    exit;
}

if (isset($_GET['delete_pairing'])) {
    $p_id = (int)$_GET['delete_pairing'];
    $stmt = $pdo->prepare("DELETE FROM user_meters WHERE id = ?");
    $stmt->execute([$p_id]);
    header("Location: admin.php");
    exit;
}

// ----------------- 处理编辑/修改操作 -----------------
// 1. 保存编辑后的电表信息
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_meter_submit'])) {
    $m_id = (int)$_POST['edit_m_id'];
    $m_name = trim($_POST['edit_m_name']);
    $b_name = trim($_POST['edit_b_name']);
    $limit_val = (double)$_POST['edit_limit_val'];
    
    $stmt = $pdo->prepare("UPDATE meters SET meter_name = ?, building_name = ?, usage_limit = ? WHERE id = ?");
    $stmt->execute([$m_name, $b_name, $limit_val, $m_id]);
    header("Location: admin.php");
    exit;
}

// 2. 保存编辑后的操作员账户信息
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user_submit'])) {
    $u_id = (int)$_POST['edit_u_id'];
    $uname = trim($_POST['edit_uname']);
    $urole = $_POST['edit_urole'];
    
    if (!empty($_POST['edit_upass'])) {
        $upass = password_hash($_POST['edit_upass'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET username = ?, password_hash = ?, role = ? WHERE id = ?");
        $stmt->execute([$uname, $upass, $urole, $u_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
        $stmt->execute([$uname, $urole, $u_id]);
    }
    
    if ($u_id === (int)$_SESSION['user_id']) {
        $_SESSION['username'] = $uname;
        $_SESSION['role'] = $urole;
    }
    header("Location: admin.php");
    exit;
}

// ----------------- 处理添加/绑定操作 -----------------
// 1. 建立配对关系 [2]
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_pairing'])) {
    $target_user = (int)$_POST['pair_user_id'];
    $target_meter = (int)$_POST['pair_meter_id'];
    
    $stmt = $pdo->prepare("INSERT INTO user_meters (user_id, meter_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id=user_id");
    $stmt->execute([$target_user, $target_meter]);
    header("Location: admin.php");
    exit;
}

// 2. 添加电表
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_meter'])) {
    $m_name = trim($_POST['m_name']);
    $b_name = trim($_POST['b_name']);
    $limit_val = (double)$_POST['limit_val'];
    
    $stmt = $pdo->prepare("INSERT INTO meters (meter_name, building_name, usage_limit) VALUES (?, ?, ?)");
    $stmt->execute([$m_name, $b_name, $limit_val]);
    header("Location: admin.php");
    exit;
}

// 3. 添加新操作员
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user_op'])) {
    $uname = trim($_POST['uname']);
    $upass = password_hash($_POST['upass'], PASSWORD_DEFAULT);
    $urole = $_POST['urole'];
    
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
    $stmt->execute([$uname, $upass, $urole]);
    header("Location: admin.php");
    exit;
}

// 处理系统预警参数设置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    foreach ($_POST['config'] as $key => $val) {
        $stmt = $pdo->prepare("INSERT INTO configs (cfg_key, cfg_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cfg_value = ?");
        $stmt->execute([$key, $val, $val]);
    }
    header("Location: admin.php?success=1");
    exit;
}

// ----------------- 构建高级过滤及分页看板逻辑 -----------------
$filter_meter = isset($_GET['filter_meter']) && $_GET['filter_meter'] !== '' ? (int)$_GET['filter_meter'] : null;
$filter_status = isset($_GET['filter_status']) && $_GET['filter_status'] !== '' ? $_GET['filter_status'] : null;
$filter_date = isset($_GET['filter_date']) && $_GET['filter_date'] !== '' ? $_GET['filter_date'] : null;

$where_clauses = [];
$sql_params = [];

if ($filter_meter !== null) {
    $where_clauses[] = "r.meter_id = ?";
    $sql_params[] = $filter_meter;
}
if ($filter_date !== null) {
    $where_clauses[] = "r.submitted_date = ?";
    $sql_params[] = $filter_date;
}

$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

$all_stmt = $pdo->prepare("
    SELECT r.*, m.meter_name, m.building_name, m.usage_limit, u.username 
    FROM readings r
    JOIN meters m ON r.meter_id = m.id
    JOIN users u ON r.user_id = u.id
    $where_sql
    ORDER BY r.submitted_at DESC
");
$all_stmt->execute($sql_params);
$all_records = $all_stmt->fetchAll();

$filtered_records = [];
foreach ($all_records as $rec) {
    $prev_stmt = $pdo->prepare("SELECT reading_value FROM readings WHERE meter_id = ? AND submitted_date < ? ORDER BY submitted_date DESC LIMIT 1");
    $prev_stmt->execute([$rec['meter_id'], $rec['submitted_date']]);
    $prev_row = $prev_stmt->fetch();
    
    $usage_diff = 'N/A';
    $is_over = false;
    if ($prev_row) {
        $usage_diff = (double)$rec['reading_value'] - (double)$prev_row['reading_value'];
        if ($usage_diff > (double)$rec['usage_limit']) {
            $is_over = true;
        }
    }
    
    $rec['calculated_usage'] = $usage_diff;
    $rec['is_over'] = $is_over;
    
    if ($filter_status === 'exceeded' && !$is_over) {
        continue;
    }
    if ($filter_status === 'normal' && $is_over) {
        continue;
    }
    
    $filtered_records[] = $rec;
}

$records_per_page = 10;
$total_records = count($filtered_records);
$total_pages = max(1, ceil($total_records / $records_per_page));
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

if ($current_page > $total_pages) {
    $current_page = $total_pages;
}
$offset = ($current_page - 1) * $records_per_page;
$readings = array_slice($filtered_records, $offset, $records_per_page);

// 获取大盘数据
$meters = $pdo->query("SELECT * FROM meters ORDER BY meter_name ASC")->fetchAll();
$users_list = $pdo->query("SELECT id, username, role FROM users ORDER BY username ASC")->fetchAll();

$pairings = $pdo->query("
    SELECT um.id, u.username, m.meter_name, m.building_name 
    FROM user_meters um
    JOIN users u ON um.user_id = u.id
    JOIN meters m ON um.meter_id = m.id
    ORDER BY u.username ASC
")->fetchAll();

function build_page_url($target_page) {
    $params = $_GET;
    $params['page'] = $target_page;
    return "?" . http_build_query($params);
}
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
                <form method="POST" class="inline-block">
                    <input type="hidden" name="action" value="set_lang">
                    <select name="lang_val" onchange="this.form.submit()" class="bg-gray-100 dark:bg-gray-700 text-xs rounded border p-1 focus:outline-none">
                        <option value="en" <?php echo ($lang === 'en')?'selected':''; ?>>English</option>
                        <option value="zh" <?php echo ($lang === 'zh')?'selected':''; ?>>中文</option>
                        <option value="ms" <?php echo ($lang === 'ms')?'selected':''; ?>>Bahasa Melayu</option>
                    </select>
                </form>

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

        <!-- ----------------- 筛选器卡片 (配备 Cookie 状态折叠箭头) ----------------- -->
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 p-4 relative">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 flex items-center gap-1.5">
                    <i data-lucide="sliders-horizontal" class="w-4 h-4 text-purple-500"></i>
                    <?php echo __('filter_title'); ?>
                </h3>
                <button type="button" onclick="toggleCard('card_filter')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <i data-lucide="<?php echo $collapse_filter ? 'chevron-down' : 'chevron-up'; ?>" id="card_filter-icon" class="w-5 h-5"></i>
                </button>
            </div>
            
            <div id="card_filter-body" class="<?php echo $collapse_filter ? 'hidden' : ''; ?>">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 text-xs">
                    <!-- 仪表板筛选电表 Combobox 自定义匹配 -->
                    <div class="relative" id="filter-meter-combo">
                        <div class="flex items-center">
                            <input type="text" 
                                   id="filter-meter-search" 
                                   placeholder="<?php echo __('search_meter_placeholder'); ?>" 
                                   class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 pr-10 focus:ring-1 focus:ring-purple-500 outline-none">
                            <span class="absolute right-3 text-gray-400">
                                <i data-lucide="chevron-down" class="w-4 h-4"></i>
                            </span>
                        </div>
                        <input type="hidden" name="filter_meter" id="filter-meter-val" value="<?php echo htmlspecialchars($filter_meter ?? ''); ?>">
                        <div id="filter-meter-dropdown" class="absolute left-0 right-0 mt-1 max-h-48 overflow-y-auto bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl hidden z-50"></div>
                    </div>

                    <div>
                        <select name="filter_status" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-purple-500 outline-none">
                            <option value=""><?php echo __('filter_all_status'); ?></option>
                            <option value="exceeded" <?php echo ($filter_status === 'exceeded') ? 'selected':''; ?>><?php echo __('filter_only_exceeded'); ?></option>
                            <option value="normal" <?php echo ($filter_status === 'normal') ? 'selected':''; ?>><?php echo __('filter_only_normal'); ?></option>
                        </select>
                    </div>
                    <div>
                        <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date ?? ''); ?>" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-1.5 focus:ring-1 focus:ring-purple-500 outline-none">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="w-1/2 bg-purple-600 text-white rounded font-bold hover:bg-purple-700 transition flex items-center justify-center gap-1">
                            <i data-lucide="search" class="w-3.5 h-3.5"></i>
                            <?php echo __('btn_filter'); ?>
                        </button>
                        <a href="admin.php" class="w-1/2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded font-semibold hover:opacity-80 transition flex items-center justify-center gap-1">
                            <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                            <?php echo __('btn_reset'); ?>
                        </a>
                    </div>
                </form>
            </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <section class="lg:col-span-2 space-y-6">
                <!-- 历史抄表卡片 -->
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
                                <?php foreach($readings as $r): ?>
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
                                            <?php echo is_numeric($r['calculated_usage']) ? $r['calculated_usage'] . ' kWh' : 'N/A'; ?>
                                        </td>
                                        <td class="py-3 px-2">
                                            <?php if($r['is_over']): ?>
                                                <span class="bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200 px-2 py-0.5 rounded text-[10px] font-bold">
                                                    ▲ <?php echo __('exceeded'); ?> (+<?php echo $r['calculated_usage'] - $r['usage_limit']; ?>)
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

                    <?php if ($total_pages > 1): ?>
                        <div class="flex items-center justify-between border-t border-gray-100 dark:border-gray-700 mt-6 pt-4 text-xs">
                            <span class="text-gray-500 dark:text-gray-400">
                                <?php echo str_replace(['{current}', '{total}'], [$current_page, $total_pages], __('page_info')); ?>
                            </span>
                            <div class="inline-flex gap-1">
                                <a href="<?php echo build_page_url(1); ?>" class="px-2.5 py-1.5 rounded border border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 font-semibold <?php echo ($current_page <= 1) ? 'pointer-events-none opacity-50' : ''; ?>">
                                    « First
                                </a>
                                <a href="<?php echo build_page_url($current_page - 1); ?>" class="px-2.5 py-1.5 rounded border border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 font-semibold <?php echo ($current_page <= 1) ? 'pointer-events-none opacity-50' : ''; ?>">
                                    ‹ <?php echo __('prev_page'); ?>
                                </a>
                                <a href="<?php echo build_page_url($current_page + 1); ?>" class="px-2.5 py-1.5 rounded border border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 font-semibold <?php echo ($current_page >= $total_pages) ? 'pointer-events-none opacity-50' : ''; ?>">
                                    <?php echo __('next_page'); ?> ›
                                </a>
                                <a href="<?php echo build_page_url($total_pages); ?>" class="px-2.5 py-1.5 rounded border border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 font-semibold <?php echo ($current_page >= $total_pages) ? 'pointer-events-none opacity-50' : ''; ?>">
                                    Last »
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ----------------- 操作员与电表配对管理卡片 ----------------- -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 p-6">
                    <div class="flex justify-between items-center mb-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                        <h3 class="text-sm font-bold flex items-center gap-1.5">
                            <i data-lucide="key-round" class="text-indigo-500"></i>
                            <?php echo __('pairing_management'); ?>
                        </h3>
                        <button type="button" onclick="toggleCard('card_pairing')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                            <i data-lucide="<?php echo $collapse_pairing ? 'chevron-down' : 'chevron-up'; ?>" id="card_pairing-icon" class="w-5 h-5"></i>
                        </button>
                    </div>

                    <div id="card_pairing-body" class="grid grid-cols-1 md:grid-cols-3 gap-6 <?php echo $collapse_pairing ? 'hidden' : ''; ?>">
                        <form method="POST" class="space-y-3 text-xs md:col-span-1">
                            <input type="hidden" name="add_pairing" value="1">
                            
                            <!-- 操作员选择：升级为模糊搜索组件 -->
                            <div class="relative" id="pair-user-combo">
                                <label class="block font-semibold mb-1"><?php echo __('select_operator'); ?></label>
                                <div class="relative">
                                    <input type="text" 
                                           id="pair-user-search" 
                                           placeholder="<?php echo __('search_operator_placeholder'); ?>" 
                                           class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 pr-10 focus:ring-1 focus:ring-purple-500 outline-none">
                                    <span class="absolute right-3 top-2 text-gray-400">
                                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                                    </span>
                                </div>
                                <input type="hidden" name="pair_user_id" id="pair-user-val" required>
                                <div id="pair-user-dropdown" class="absolute left-0 right-0 mt-1 max-h-48 overflow-y-auto bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl hidden z-50"></div>
                            </div>

                            <!-- 关联电表选择：升级为模糊搜索组件 -->
                            <div class="relative" id="pair-meter-combo">
                                <label class="block font-semibold mb-1"><?php echo __('select_meter_pair'); ?></label>
                                <div class="relative">
                                    <input type="text" 
                                           id="pair-meter-search" 
                                           placeholder="<?php echo __('search_meter_placeholder'); ?>" 
                                           class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 pr-10 focus:ring-1 focus:ring-purple-500 outline-none">
                                    <span class="absolute right-3 top-2 text-gray-400">
                                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                                    </span>
                                </div>
                                <input type="hidden" name="pair_meter_id" id="pair-meter-val" required>
                                <div id="pair-meter-dropdown" class="absolute left-0 right-0 mt-1 max-h-48 overflow-y-auto bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl hidden z-50"></div>
                            </div>

                            <button type="submit" class="w-full bg-indigo-600 text-white p-2 rounded font-bold hover:bg-indigo-700 transition">
                                <?php echo __('btn_pair'); ?>
                            </button>
                        </form>

                        <div class="md:col-span-2 overflow-x-auto text-xs border border-gray-100 dark:border-gray-700 rounded-lg p-3 bg-gray-50/50 dark:bg-gray-800/50">
                            <!-- 前端即时表格模糊搜索框 -->
                            <div class="mb-3 flex items-center bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded px-2.5">
                                <span class="text-gray-400 mr-2"><i data-lucide="search" class="w-4 h-4"></i></span>
                                <input type="text" 
                                       onkeyup="filterTableRows('pairingTable', this.value)" 
                                       placeholder="<?php echo __('table_quick_search_placeholder'); ?>" 
                                       class="w-full bg-transparent p-1.5 outline-none font-semibold">
                            </div>

                            <h4 class="font-bold mb-3 text-indigo-600"><?php echo __('active_pairings'); ?></h4>
                            <table class="w-full text-left border-collapse" id="pairingTable">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700 text-gray-500 font-bold uppercase text-[10px]">
                                        <th class="py-2 px-1"><?php echo __('operator'); ?></th>
                                        <th class="py-2 px-1"><?php echo __('select_meter_pair'); ?></th>
                                        <th class="py-2 px-1 text-right"><?php echo __('status'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($pairings)): ?>
                                        <tr>
                                            <td colspan="3" class="py-3 text-center text-gray-400"><?php echo __('no_pairings'); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach($pairings as $p): ?>
                                        <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-100/30 transition">
                                            <td class="py-2 px-1 font-semibold text-purple-600"><?php echo htmlspecialchars($p['username']); ?></td>
                                            <td class="py-2 px-1 text-gray-500">
                                                <?php echo htmlspecialchars($p['building_name']); ?> 
                                                <span class="text-[10px] bg-gray-200 dark:bg-gray-700 px-1 rounded block w-max"><?php echo htmlspecialchars($p['meter_name']); ?></span>
                                            </td>
                                            <td class="py-2 px-1 text-right">
                                                <a href="admin.php?delete_pairing=<?php echo $p['id']; ?>" onclick="return confirm('<?php echo __('confirm_delete'); ?>');" class="bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-300 p-1 rounded inline-block hover:opacity-80 transition" title="Unlink">
                                                    <i data-lucide="link-2-off" class="w-3.5 h-3.5"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ----------------- 数据管理大卡片 ----------------- -->
                <div class="grid grid-cols-1 gap-6">
                    
                    <!-- 电表列表卡片 -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 p-6">
                        <div class="flex justify-between items-center mb-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                            <h3 class="text-sm font-bold flex items-center gap-1.5">
                                <i data-lucide="plus-circle" class="text-green-500"></i>
                                <?php echo __('add_meter'); ?> / <?php echo __('meters_title'); ?>
                            </h3>
                            <button type="button" onclick="toggleCard('card_meters')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                                <i data-lucide="<?php echo $collapse_meters ? 'chevron-down' : 'chevron-up'; ?>" id="card_meters-icon" class="w-5 h-5"></i>
                            </button>
                        </div>

                        <div id="card_meters-body" class="grid grid-cols-1 md:grid-cols-3 gap-6 <?php echo $collapse_meters ? 'hidden' : ''; ?>">
                            <form method="POST" class="space-y-3 text-xs md:col-span-1">
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

                            <div class="md:col-span-2 overflow-x-auto text-xs border border-gray-100 dark:border-gray-700 rounded-lg p-3 bg-gray-50/50 dark:bg-gray-800/50">
                                <!-- 前端即时表格模糊搜索框 -->
                                <div class="mb-3 flex items-center bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded px-2.5">
                                    <span class="text-gray-400 mr-2"><i data-lucide="search" class="w-4 h-4"></i></span>
                                    <input type="text" 
                                           onkeyup="filterTableRows('metersTable', this.value)" 
                                           placeholder="<?php echo __('table_quick_search_placeholder'); ?>" 
                                           class="w-full bg-transparent p-1.5 outline-none font-semibold">
                                </div>

                                <h4 class="font-bold mb-3 text-purple-600"><?php echo __('meters_title'); ?></h4>
                                <table class="w-full text-left border-collapse" id="metersTable">
                                    <thead>
                                        <tr class="border-b border-gray-200 dark:border-gray-700 text-gray-500 font-bold uppercase text-[10px]">
                                            <th class="py-2 px-1"><?php echo __('meter_name'); ?></th>
                                            <th class="py-2 px-1"><?php echo __('building'); ?></th>
                                            <th class="py-2 px-1"><?php echo __('limit'); ?></th>
                                            <th class="py-2 px-1 text-right"><?php echo __('status'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($meters)): ?>
                                            <tr>
                                                <td colspan="4" class="py-3 text-center text-gray-400"><?php echo __('no_meters'); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach($meters as $m): ?>
                                            <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-100/30 transition">
                                                <td class="py-2 px-1 font-semibold"><?php echo htmlspecialchars($m['meter_name']); ?></td>
                                                <td class="py-2 px-1 text-gray-500"><?php echo htmlspecialchars($m['building_name']); ?></td>
                                                <td class="py-2 px-1 font-bold"><?php echo $m['usage_limit']; ?> kWh</td>
                                                <td class="py-2 px-1 text-right space-x-1">
                                                    <button type="button" 
                                                            onclick="openEditMeterModal(<?php echo $m['id']; ?>, '<?php echo htmlspecialchars($m['meter_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($m['building_name'], ENT_QUOTES); ?>', <?php echo $m['usage_limit']; ?>)" 
                                                            class="bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300 p-1 rounded inline-block hover:opacity-80 transition" 
                                                            title="<?php echo __('edit_btn'); ?>">
                                                        <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                                    </button>
                                                    <a href="admin.php?delete_meter=<?php echo $m['id']; ?>" onclick="return confirm('<?php echo __('confirm_delete'); ?>');" class="bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-300 p-1 rounded inline-block hover:opacity-80 transition" title="Delete">
                                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- 用户操作员管理卡片 -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 p-6">
                        <div class="flex justify-between items-center mb-4 border-b border-gray-100 dark:border-gray-700 pb-2">
                            <h3 class="text-sm font-bold flex items-center gap-1.5">
                                <i data-lucide="user-plus" class="text-blue-500"></i>
                                <?php echo __('add_user'); ?> / <?php echo __('users_title'); ?>
                            </h3>
                            <button type="button" onclick="toggleCard('card_users')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                                <i data-lucide="<?php echo $collapse_users ? 'chevron-down' : 'chevron-up'; ?>" id="card_users-icon" class="w-5 h-5"></i>
                            </button>
                        </div>

                        <div id="card_users-body" class="grid grid-cols-1 md:grid-cols-3 gap-6 <?php echo $collapse_users ? 'hidden' : ''; ?>">
                            <form method="POST" class="space-y-3 text-xs md:col-span-1">
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

                            <div class="md:col-span-2 overflow-x-auto text-xs border border-gray-100 dark:border-gray-700 rounded-lg p-3 bg-gray-50/50 dark:bg-gray-800/50">
                                <!-- 前端即时表格模糊搜索框 -->
                                <div class="mb-3 flex items-center bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded px-2.5">
                                    <span class="text-gray-400 mr-2"><i data-lucide="search" class="w-4 h-4"></i></span>
                                    <input type="text" 
                                           onkeyup="filterTableRows('usersTable', this.value)" 
                                           placeholder="<?php echo __('table_quick_search_placeholder'); ?>" 
                                           class="w-full bg-transparent p-1.5 outline-none font-semibold">
                                </div>

                                <h4 class="font-bold mb-3 text-purple-600"><?php echo __('users_title'); ?></h4>
                                <table class="w-full text-left border-collapse" id="usersTable">
                                    <thead>
                                        <tr class="border-b border-gray-200 dark:border-gray-700 text-gray-500 font-bold uppercase text-[10px]">
                                            <th class="py-2 px-1"><?php echo __('username'); ?></th>
                                            <th class="py-2 px-1"><?php echo __('role'); ?></th>
                                            <th class="py-2 px-1 text-right"><?php echo __('status'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($users_list)): ?>
                                            <tr>
                                                <td colspan="3" class="py-3 text-center text-gray-400"><?php echo __('no_users'); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach($users_list as $u): ?>
                                            <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-100/30 transition">
                                                <td class="py-2 px-1 font-semibold"><?php echo htmlspecialchars($u['username']); ?></td>
                                                <td class="py-2 px-1">
                                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?php echo ($u['role'] === 'admin') ? 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'; ?>">
                                                        <?php echo ($u['role'] === 'admin') ? __('admin') : __('user'); ?>
                                                    </span>
                                                </td>
                                                <td class="py-2 px-1 text-right space-x-1">
                                                    <button type="button" 
                                                            onclick="openEditUserModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>', '<?php echo $u['role']; ?>')" 
                                                            class="bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300 p-1 rounded inline-block hover:opacity-80 transition" 
                                                            title="<?php echo __('edit_btn'); ?>">
                                                        <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                                    </button>
                                                    <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                                        <a href="admin.php?delete_user=<?php echo $u['id']; ?>" onclick="return confirm('<?php echo __('confirm_delete'); ?>');" class="bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-300 p-1 rounded inline-block hover:opacity-80 transition" title="Delete">
                                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-gray-400 text-[10px] italic"><?php echo __('logged_in_status'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </section>

            <!-- 右侧配置区域 -->
            <section class="space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-base font-bold flex items-center gap-2">
                            <i data-lucide="settings-2" class="text-blue-500"></i>
                            <?php echo __('alert_config'); ?>
                        </h2>
                        <button type="button" onclick="toggleCard('card_settings')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                            <i data-lucide="<?php echo $collapse_settings ? 'chevron-down' : 'chevron-up'; ?>" id="card_settings-icon" class="w-5 h-5"></i>
                        </button>
                    </div>
                    
                    <form method="POST" class="space-y-4 text-xs <?php echo $collapse_settings ? 'hidden' : ''; ?>" id="card_settings-body">
                        <input type="hidden" name="save_settings" value="1">
                        
                        <div>
                            <label class="block font-semibold mb-1.5"><?php echo __('provider'); ?></label>
                            <select name="config[notification_provider]" id="notifProvider" onchange="toggleConfigFields()" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none font-semibold">
                                <option value="telegram" <?php echo (($system_configs['notification_provider'] ?? 'telegram') === 'telegram') ? 'selected':''; ?>>Telegram Bot</option>
                                <option value="whatsapp" <?php echo (($system_configs['notification_provider'] ?? '') === 'whatsapp') ? 'selected':''; ?>>WhatsApp Gateway</option>
                            </select>
                        </div>

                        <div>
                            <label class="block font-semibold mb-1.5"><?php echo __('missing_deadline_label'); ?></label>
                            <input type="time" name="config[submission_deadline]" value="<?php echo htmlspecialchars($system_configs['submission_deadline'] ?? '18:00'); ?>" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none">
                        </div>

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

    <!-- 电表修改模态框组件 -->
    <div id="edit-meter-modal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white dark:bg-gray-800 rounded-lg max-w-sm w-full shadow-2xl p-6 relative border border-gray-100 dark:border-gray-700">
            <button type="button" onclick="closeEditMeterModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
            <h3 class="text-base font-bold mb-4 flex items-center gap-2">
                <i data-lucide="edit" class="text-blue-500"></i>
                <?php echo __('edit_meter_title'); ?>
            </h3>
            <form method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="edit_meter_submit" value="1">
                <input type="hidden" name="edit_m_id" id="edit_m_id">
                <div>
                    <label class="block font-semibold mb-1"><?php echo __('meter_code_label'); ?></label>
                    <input type="text" name="edit_m_name" id="edit_m_name" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none font-semibold">
                </div>
                <div>
                    <label class="block font-semibold mb-1"><?php echo __('building_location_label'); ?></label>
                    <input type="text" name="edit_b_name" id="edit_b_name" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block font-semibold mb-1"><?php echo __('daily_limit_label'); ?></label>
                    <input type="number" step="0.1" name="edit_limit_val" id="edit_limit_val" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none font-bold">
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="closeEditMeterModal()" class="w-1/2 bg-gray-200 dark:bg-gray-700 dark:text-gray-200 text-gray-700 p-2 rounded font-semibold hover:opacity-80 transition">
                        <?php echo __('btn_cancel'); ?>
                    </button>
                    <button type="submit" class="w-1/2 bg-blue-600 text-white p-2 rounded font-bold hover:bg-blue-700 transition">
                        <?php echo __('btn_save_changes'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 用户修改模态框组件 -->
    <div id="edit-user-modal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white dark:bg-gray-800 rounded-lg max-w-sm w-full shadow-2xl p-6 relative border border-gray-100 dark:border-gray-700">
            <button type="button" onclick="closeEditUserModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
            <h3 class="text-base font-bold mb-4 flex items-center gap-2">
                <i data-lucide="user-cog" class="text-blue-500"></i>
                <?php echo __('edit_user_title'); ?>
            </h3>
            <form method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="edit_user_submit" value="1">
                <input type="hidden" name="edit_u_id" id="edit_u_id">
                <div>
                    <label class="block font-semibold mb-1"><?php echo __('username'); ?></label>
                    <input type="text" name="edit_uname" id="edit_uname" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none font-semibold">
                </div>
                <div>
                    <label class="block font-semibold mb-1"><?php echo __('password'); ?> (<?php echo __('btn_submit'); ?>)</label>
                    <input type="password" name="edit_upass" placeholder="<?php echo __('pw_placeholder_edit'); ?>" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block font-semibold mb-1"><?php echo __('role'); ?></label>
                    <select name="edit_urole" id="edit_urole" class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-1 focus:ring-blue-500 outline-none font-semibold">
                        <option value="user"><?php echo __('user'); ?></option>
                        <option value="admin"><?php echo __('admin'); ?></option>
                    </select>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="closeEditUserModal()" class="w-1/2 bg-gray-200 dark:bg-gray-700 dark:text-gray-200 text-gray-700 p-2 rounded font-semibold hover:opacity-80 transition">
                        <?php echo __('btn_cancel'); ?>
                    </button>
                    <button type="submit" class="w-1/2 bg-blue-600 text-white p-2 rounded font-bold hover:bg-blue-700 transition">
                        <?php echo __('btn_save_changes'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

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

    <!-- 通用高阶模糊匹配 Combobox 与卡片动态折叠 JS 实现 [2][4] -->
    <script>
        lucide.createIcons();

        // 统一注入后端大盘数据，供 JS 初始化 combobox 使用
        const rawMeters = <?php echo json_encode($meters); ?>;
        const rawOperators = <?php echo json_encode($users_list); ?>;

        // ----------------- 通用折叠卡片 Cookie 偏好机制 -----------------
        function setCookie(name, value, days) {
            const date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            document.cookie = name + "=" + value + ";path=/;expires=" + date.toUTCString();
        }

        function toggleCard(cardId) {
            const body = document.getElementById(cardId + '-body');
            const icon = document.getElementById(cardId + '-icon');
            const isCollapsed = body.classList.contains('hidden');

            if (isCollapsed) {
                body.classList.remove('hidden');
                icon.setAttribute('data-lucide', 'chevron-up');
                setCookie(cardId + '_collapsed', 'false', 30);
            } else {
                body.classList.add('hidden');
                icon.setAttribute('data-lucide', 'chevron-down');
                setCookie(cardId + '_collapsed', 'true', 30);
            }
            lucide.createIcons();
        }

        // ----------------- 通用表格内每一行前端瞬时过滤机制 -----------------
        function filterTableRows(tableId, query) {
            const table = document.getElementById(tableId);
            const trs = table.getElementsByTagName('tr');
            const q = query.toLowerCase().trim();

            // 跳过表头 tr，从索引1开始模糊检索内容
            for (let i = 1; i < trs.length; i++) {
                const tr = trs[i];
                const text = tr.textContent.toLowerCase();
                if (text.includes(q)) {
                    tr.style.display = '';
                } else {
                    tr.style.display = 'none';
                }
            }
        }

        // ----------------- 通用手写模糊检索选择组件 (Fuzzy Search Combobox) -----------------
        function setupCombobox(inputId, hiddenId, dropdownId, rawData, displayField, valueField, secondaryField = '') {
            const searchInput = document.getElementById(inputId);
            const hiddenInput = document.getElementById(hiddenId);
            const dropdown = document.getElementById(dropdownId);

            // 如果当前输入框有预设的值（如筛选看板回显），自动进行文本还原匹配
            if (hiddenInput.value !== '') {
                const found = rawData.find(item => item[valueField] == hiddenInput.value);
                if (found) {
                    searchInput.value = secondaryField ? `${found[secondaryField]} (${found[displayField]})` : found[displayField];
                }
            }

            function render() {
                dropdown.innerHTML = '';
                const query = searchInput.value.toLowerCase().trim();
                
                const filtered = rawData.filter(item => {
                    const primaryMatch = item[displayField].toLowerCase().includes(query);
                    const secondaryMatch = secondaryField ? item[secondaryField].toLowerCase().includes(query) : false;
                    return primaryMatch || secondaryMatch;
                });

                if (filtered.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'p-3 text-xs text-gray-400 italic text-center';
                    empty.innerText = 'No matches found.';
                    dropdown.appendChild(empty);
                } else {
                    filtered.forEach(item => {
                        const opt = document.createElement('div');
                        opt.className = 'p-2 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer font-semibold transition text-xs flex flex-col';
                        if (secondaryField) {
                            opt.innerHTML = `
                                <span>${item[secondaryField]}</span>
                                <span class="text-[10px] text-gray-400 font-normal">${item[displayField]}</span>
                            `;
                        } else {
                            opt.innerHTML = `<span>${item[displayField]}</span>`;
                        }

                        opt.addEventListener('mousedown', () => {
                            searchInput.value = secondaryField ? `${item[secondaryField]} (${item[displayField]})` : item[displayField];
                            hiddenInput.value = item[valueField];
                            dropdown.classList.add('hidden');
                        });
                        dropdown.appendChild(opt);
                    });
                }
            }

            searchInput.addEventListener('input', () => {
                hiddenInput.value = ''; // 键盘打字，清空真实字段，直至再次合法选择
                render();
                dropdown.classList.remove('hidden');
            });

            searchInput.addEventListener('focus', () => {
                render();
                dropdown.classList.remove('hidden');
            });

            searchInput.addEventListener('blur', () => {
                setTimeout(() => {
                    dropdown.classList.add('hidden');
                    if (hiddenInput.value === '') {
                        searchInput.value = '';
                    }
                }, 200);
            });
        }

        // ----------------- 初始化三个高阶模糊检索选择器 [2][4] -----------------
        // 1. 仪表板检索看板：筛选电表
        setupCombobox('filter-meter-search', 'filter-meter-val', 'filter-meter-dropdown', rawMeters, 'meter_name', 'id', 'building_name');

        // 2. 配对管理表单：选择操作员
        const operatorsOnly = rawOperators.filter(u => u.role === 'user');
        setupCombobox('pair-user-search', 'pair-user-val', 'pair-user-dropdown', operatorsOnly, 'username', 'id');

        // 3. 配对管理表单：选择电表
        setupCombobox('pair-meter-search', 'pair-meter-val', 'pair-meter-dropdown', rawMeters, 'meter_name', 'id', 'building_name');

        // ----------------- 模态框打开/关闭函数 [修正：补齐此全局关键函数] -----------------
        function toggleModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.toggle('hidden');
            }
        }

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

        function openEditMeterModal(id, name, building, limit) {
            document.getElementById('edit_m_id').value = id;
            document.getElementById('edit_m_name').value = name;
            document.getElementById('edit_b_name').value = building;
            document.getElementById('edit_limit_val').value = limit;
            
            document.getElementById('edit-meter-modal').classList.remove('hidden');
        }

        function closeEditMeterModal() {
            document.getElementById('edit-meter-modal').classList.add('hidden');
        }

        function openEditUserModal(id, username, role) {
            document.getElementById('edit_u_id').value = id;
            document.getElementById('edit_uname').value = username;
            document.getElementById('edit_urole').value = role;
            
            document.getElementById('edit-user-modal').classList.remove('hidden');
        }

        function closeEditUserModal() {
            document.getElementById('edit-user-modal').classList.add('hidden');
        }
        
        window.onload = function() {
            toggleConfigFields();
        };
    </script>
</body>
</html>