<?php
require_once 'db.php';

// 安全守卫：非管理员禁止访问
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// ----------------- 处理删除操作 (电表和操作员) -----------------
// 1. 删除电表
if (isset($_GET['delete_meter'])) {
    $m_id = (int)$_GET['delete_meter'];
    $stmt = $pdo->prepare("DELETE FROM meters WHERE id = ?");
    $stmt->execute([$m_id]);
    header("Location: admin.php");
    exit;
}

// 2. 删除操作员账号 (禁止管理员删除自己以防止死锁)
if (isset($_GET['delete_user'])) {
    $u_id = (int)$_GET['delete_user'];
    if ($u_id !== (int)$_SESSION['user_id']) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$u_id]);
    }
    header("Location: admin.php");
    exit;
}

// ----------------- 处理编辑/修改操作 (电表和操作员) -----------------
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
        // 如果输入了新密码，则哈希更新
        $upass = password_hash($_POST['edit_upass'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET username = ?, password_hash = ?, role = ? WHERE id = ?");
        $stmt->execute([$uname, $upass, $urole, $u_id]);
    } else {
        // 未输入密码则保留原密码
        $stmt = $pdo->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
        $stmt->execute([$uname, $urole, $u_id]);
    }
    
    // 如果修改的是当前登录管理员自己，同步更新 SESSION 信息
    if ($u_id === (int)$_SESSION['user_id']) {
        $_SESSION['username'] = $uname;
        $_SESSION['role'] = $urole;
    }
    header("Location: admin.php");
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

// 添加新电表
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

// ----------------- 服务端分页器 -----------------
$records_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $records_per_page;

$count_stmt = $pdo->query("SELECT COUNT(*) FROM readings");
$total_records = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_records / $records_per_page));

if ($current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $records_per_page;
}

$stmt = $pdo->prepare("
    SELECT r.*, m.meter_name, m.building_name, m.usage_limit, u.username 
    FROM readings r
    JOIN meters m ON r.meter_id = m.id
    JOIN users u ON r.user_id = u.id
    ORDER BY r.submitted_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$readings = $stmt->fetchAll();

// 获取可用电表
$meters = $pdo->query("SELECT * FROM meters ORDER BY meter_name ASC")->fetchAll();

// 获取系统用户
$users = $pdo->query("SELECT id, username, role FROM users ORDER BY username ASC")->fetchAll();
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

                    <!-- 物理分页器 UI -->
                    <?php if ($total_pages > 1): ?>
                        <div class="flex items-center justify-between border-t border-gray-100 dark:border-gray-700 mt-6 pt-4 text-xs">
                            <span class="text-gray-500 dark:text-gray-400">
                                <?php echo str_replace(['{current}', '{total}'], [$current_page, $total_pages], __('page_info')); ?>
                            </span>
                            <div class="inline-flex gap-1">
                                <a href="?page=1" class="px-2.5 py-1.5 rounded border border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 font-semibold <?php echo ($current_page <= 1) ? 'pointer-events-none opacity-50' : ''; ?>">
                                    « First
                                </a>
                                <a href="?page=<?php echo $current_page - 1; ?>" class="px-2.5 py-1.5 rounded border border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 font-semibold <?php echo ($current_page <= 1) ? 'pointer-events-none opacity-50' : ''; ?>">
                                    ‹ <?php echo __('prev_page'); ?>
                                </a>
                                <a href="?page=<?php echo $current_page + 1; ?>" class="px-2.5 py-1.5 rounded border border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 font-semibold <?php echo ($current_page >= $total_pages) ? 'pointer-events-none opacity-50' : ''; ?>">
                                    <?php echo __('next_page'); ?> ›
                                </a>
                                <a href="?page=<?php echo $total_pages; ?>" class="px-2.5 py-1.5 rounded border border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 font-semibold <?php echo ($current_page >= $total_pages) ? 'pointer-events-none opacity-50' : ''; ?>">
                                    Last »
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 数据管理区域：电表管理及用户账号管理 -->
                <div class="grid grid-cols-1 gap-6">
                    
                    <!-- 电表管理分栏 (已支持增删改) -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 p-6">
                        <h3 class="text-sm font-bold mb-4 flex items-center gap-1.5 border-b border-gray-100 dark:border-gray-700 pb-2">
                            <i data-lucide="plus-circle" class="text-green-500"></i>
                            <?php echo __('add_meter'); ?> / <?php echo __('meters_title'); ?>
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <!-- 添加电表表单 -->
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

                            <!-- 电表管理列表及删除/修改操作 -->
                            <div class="md:col-span-2 overflow-x-auto text-xs border border-gray-100 dark:border-gray-700 rounded-lg p-3 bg-gray-50/50 dark:bg-gray-800/50">
                                <h4 class="font-bold mb-3 text-purple-600"><?php echo __('meters_title'); ?></h4>
                                <table class="w-full text-left border-collapse">
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
                                                    <!-- 修改电表按钮 -->
                                                    <button type="button" 
                                                            onclick="openEditMeterModal(<?php echo $m['id']; ?>, '<?php echo htmlspecialchars($m['meter_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($m['building_name'], ENT_QUOTES); ?>', <?php echo $m['usage_limit']; ?>)" 
                                                            class="bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300 p-1 rounded inline-block hover:opacity-80 transition" 
                                                            title="<?php echo __('edit_btn'); ?>">
                                                        <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                                    </button>
                                                    <!-- 安全删除电表 -->
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

                    <!-- 用户操作员管理分栏 (已支持增删改与多语言Logged In绑定) -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 p-6">
                        <h3 class="text-sm font-bold mb-4 flex items-center gap-1.5 border-b border-gray-100 dark:border-gray-700 pb-2">
                            <i data-lucide="user-plus" class="text-blue-500"></i>
                            <?php echo __('add_user'); ?> / <?php echo __('users_title'); ?>
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <!-- 注册操作员表单 -->
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

                            <!-- 用户列表及删除/修改操作 -->
                            <div class="md:col-span-2 overflow-x-auto text-xs border border-gray-100 dark:border-gray-700 rounded-lg p-3 bg-gray-50/50 dark:bg-gray-800/50">
                                <h4 class="font-bold mb-3 text-purple-600"><?php echo __('users_title'); ?></h4>
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="border-b border-gray-200 dark:border-gray-700 text-gray-500 font-bold uppercase text-[10px]">
                                            <th class="py-2 px-1"><?php echo __('username'); ?></th>
                                            <th class="py-2 px-1"><?php echo __('role'); ?></th>
                                            <th class="py-2 px-1 text-right"><?php echo __('status'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($users)): ?>
                                            <tr>
                                                <td colspan="3" class="py-3 text-center text-gray-400"><?php echo __('no_users'); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach($users as $u): ?>
                                            <tr class="border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-100/30 transition">
                                                <td class="py-2 px-1 font-semibold"><?php echo htmlspecialchars($u['username']); ?></td>
                                                <td class="py-2 px-1">
                                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?php echo ($u['role'] === 'admin') ? 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'; ?>">
                                                        <?php echo ($u['role'] === 'admin') ? __('admin') : __('user'); ?>
                                                    </span>
                                                </td>
                                                <td class="py-2 px-1 text-right space-x-1">
                                                    <!-- 修改操作员按钮 -->
                                                    <button type="button" 
                                                            onclick="openEditUserModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>', '<?php echo $u['role']; ?>')" 
                                                            class="bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300 p-1 rounded inline-block hover:opacity-80 transition" 
                                                            title="<?php echo __('edit_btn'); ?>">
                                                        <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                                    </button>
                                                    <!-- 安全删除非我本人账户 (已替换Logged In的硬编码) -->
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
                    <h2 class="text-base font-bold mb-4 flex items-center gap-2">
                        <i data-lucide="settings-2" class="text-blue-500"></i>
                        <?php echo __('alert_config'); ?>
                    </h2>
                    
                    <form method="POST" class="space-y-4 text-xs">
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

    <!-- ----------------- 电表修改模态框组件 ----------------- -->
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

    <!-- ----------------- 用户修改模态框组件 ----------------- -->
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

        // ----------------- 控制电表修改模态框 -----------------
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

        // ----------------- 控制用户修改模态框 -----------------
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