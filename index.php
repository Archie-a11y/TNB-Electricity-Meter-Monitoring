<?php
require_once 'db.php';

$error_msg = '';
$success_msg = '';

// 处理语言和主题切换的POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'set_lang') {
        setcookie('app_lang', $_POST['lang_val'], time() + (3600 * 24 * 30), "/");
        header("Location: index.php");
        exit;
    }
    if ($_POST['action'] === 'set_theme') {
        setcookie('app_theme', $_POST['theme_val'], time() + (3600 * 24 * 30), "/");
        header("Location: index.php");
        exit;
    }
}

// 处理登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_form'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header("Location: index.php");
        exit;
    } else {
        $error_msg = __('login_failed');
    }
}

// 处理退出登录
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// 处理抄表提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reading'])) {
    if (!isset($_SESSION['user_id'])) {
        die(__('err_unauthorized'));
    }
    
    $meter_id = (int)$_POST['meter_id'];
    $reading_val = (double)$_POST['reading_val'];
    $today_date = date('Y-m-d');
    $now_time = date('Y-m-d H:i:s');
    
    // 检查今天是否已经提交过
    $chk_stmt = $pdo->prepare("SELECT id FROM readings WHERE meter_id = ? AND submitted_date = ?");
    $chk_stmt->execute([$meter_id, $today_date]);
    if ($chk_stmt->fetch()) {
        $error_msg = __('dup_error');
    } else {
        // 验证文件是否通过实时捕获上传
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['photo']['tmp_name'];
            $file_ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $new_filename = 'meter_' . $meter_id . '_' . time() . '.' . $file_ext;
            
            if (!is_dir('uploads')) {
                mkdir('uploads', 0777, true);
            }
            
            $dest_path = 'uploads/' . $new_filename;
            if (move_uploaded_file($tmp_name, $dest_path)) {
                
                // 写入数据库
                $ins_stmt = $pdo->prepare("INSERT INTO readings (meter_id, user_id, reading_value, photo_path, submitted_date, submitted_at) VALUES (?, ?, ?, ?, ?, ?)");
                $ins_stmt->execute([$meter_id, $_SESSION['user_id'], $reading_val, $dest_path, $today_date, $now_time]);
                
                // 计算24小时用电差额并判断是否越界
                $prev_stmt = $pdo->prepare("SELECT reading_value FROM readings WHERE meter_id = ? AND submitted_date < ? ORDER BY submitted_date DESC LIMIT 1");
                $prev_stmt->execute([$meter_id, $today_date]);
                $prev_row = $prev_stmt->fetch();
                
                if ($prev_row) {
                    $consumption = $reading_val - (double)$prev_row['reading_value'];
                    
                    // 获取当前电表的上限
                    $m_stmt = $pdo->prepare("SELECT meter_name, usage_limit FROM meters WHERE id = ?");
                    $m_stmt->execute([$meter_id]);
                    $meter_data = $m_stmt->fetch();
                    
                    if ($meter_data && $consumption > (double)$meter_data['usage_limit']) {
                        $diff_val = $consumption - (double)$meter_data['usage_limit'];
                        $alert_msg = str_replace(
                            ['{meter}', '{diff}'], 
                            [$meter_data['meter_name'], $diff_val], 
                            __('err_limit_exceeded')
                        );
                        // 触发推送网关
                        send_alert_notification($alert_msg);
                    }
                }
                
                $success_msg = __('success_submitted');
            } else {
                $error_msg = "Error moving uploaded file.";
            }
        } else {
            $error_msg = __('no_photo_err');
        }
    }
}

// 获取可用电表列表
$meters_list = [];
if (isset($_SESSION['user_id'])) {
    $meters_list = $pdo->query("SELECT * FROM meters ORDER BY meter_name ASC")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" class="<?php echo ($theme === 'dark') ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('app_title'); ?></title>
    <!-- 使用 Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
    <!-- 使用 Lucide 免费图标库 -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100 min-h-screen transition-colors duration-200">

    <!-- 顶部状态栏 -->
    <header class="bg-white dark:bg-gray-800 shadow p-4 sticky top-0 z-40">
        <div class="max-w-4xl mx-auto flex flex-wrap items-center justify-between gap-4">
            <h1 class="font-bold text-lg tracking-tight flex items-center gap-2">
                <i data-lucide="zap" class="text-yellow-500 fill-yellow-500"></i>
                <?php echo __('app_title'); ?>
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
                <button onclick="toggleModal('guide-modal')" class="p-1 rounded bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200 hover:opacity-80 transition flex items-center gap-1 text-xs px-2 font-semibold">
                    <i data-lucide="help-circle" class="w-4 h-4"></i>
                    <?php echo __('user_guide'); ?>
                </button>
                
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="index.php?logout=1" class="text-xs bg-red-500 text-white px-2 py-1 rounded hover:bg-red-600 transition font-semibold flex items-center gap-1">
                        <i data-lucide="log-out" class="w-3 h-3"></i>
                        <?php echo __('btn_logout'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="max-w-md mx-auto p-4 mt-6">

        <!-- 消息弹窗反馈 -->
        <?php if(!empty($error_msg)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 rounded text-sm flex items-center gap-2">
                <i data-lucide="alert-triangle" class="w-5 h-5 shrink-0"></i>
                <span><?php echo $error_msg; ?></span>
            </div>
        <?php endif; ?>
        <?php if(!empty($success_msg)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 mb-4 rounded text-sm flex items-center gap-2">
                <i data-lucide="check-circle" class="w-5 h-5 shrink-0"></i>
                <span><?php echo $success_msg; ?></span>
            </div>
        <?php endif; ?>

        <!-- 场景1：未登录，显示登录框 -->
        <?php if(!isset($_SESSION['user_id'])): ?>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 border border-gray-100 dark:border-gray-700">
                <h2 class="text-xl font-bold mb-4 text-center tracking-tight flex items-center justify-center gap-2">
                    <i data-lucide="lock" class="w-5 h-5"></i>
                    <?php echo __('login_title'); ?>
                </h2>
                <form method="POST">
                    <input type="hidden" name="login_form" value="1">
                    <div class="mb-4">
                        <label class="block text-xs font-semibold uppercase tracking-wider mb-1"><?php echo __('username'); ?></label>
                        <input type="text" name="username" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div class="mb-6">
                        <label class="block text-xs font-semibold uppercase tracking-wider mb-1"><?php echo __('password'); ?></label>
                        <input type="password" name="password" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <button type="submit" class="w-full bg-blue-600 text-white p-3 rounded font-bold hover:bg-blue-700 transition flex items-center justify-center gap-2">
                        <i data-lucide="check" class="w-5 h-5"></i>
                        <?php echo __('btn_login'); ?>
                    </button>
                </form>
            </div>

        <!-- 场景2：操作员登录，显示抄表表单 -->
        <?php else: ?>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 border border-gray-100 dark:border-gray-700">
                <div class="flex justify-between items-center mb-6">
                    <div class="text-sm font-semibold">
                        Hi, <span class="text-blue-500 font-bold"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                        <span class="text-xs bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded ml-1"><?php echo __('user'); ?></span>
                    </div>
                    <?php if($_SESSION['role'] === 'admin'): ?>
                        <a href="admin.php" class="text-xs bg-purple-600 text-white px-3 py-1.5 rounded hover:bg-purple-700 transition font-bold flex items-center gap-1">
                            <i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i>
                            <?php echo __('dashboard'); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <form method="POST" enctype="multipart/form-data" id="readingForm">
                    <input type="hidden" name="submit_reading" value="1">
                    
                    <!-- 选择电表 -->
                    <div class="mb-5">
                        <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5"><?php echo __('select_meter'); ?></label>
                        <select name="meter_id" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2.5 focus:ring-2 focus:ring-blue-500 outline-none">
                            <?php foreach($meters_list as $m): ?>
                                <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['building_name'] . ' (' . $m['meter_name'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 实时拍照区 -->
                    <div class="mb-5">
                        <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5"><?php echo __('photo'); ?></label>
                        <div class="flex flex-col items-center justify-center border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-4 bg-gray-50 dark:bg-gray-700/50">
                            <!-- capture为environment强制直接调用摄像头 [1] -->
                            <input type="file" name="photo" id="cameraInput" accept="image/*" capture="environment" class="hidden" required>
                            
                            <button type="button" onclick="triggerCamera()" class="bg-blue-600 text-white px-4 py-2 rounded-md font-semibold text-sm hover:bg-blue-700 transition flex items-center gap-2 mb-2">
                                <i data-lucide="camera" class="w-4 h-4"></i>
                                <?php echo __('capture_live'); ?>
                            </button>
                            
                            <p class="text-xs text-gray-500 dark:text-gray-400 text-center mb-2" id="fileNamePlaceholder"><?php echo __('no_photo_placeholder'); ?></p>
                            <img id="photoPreview" class="hidden max-h-48 w-full object-cover rounded shadow" alt="Preview">
                        </div>
                    </div>

                    <!-- 读数输入 -->
                    <div class="mb-6">
                        <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5"><?php echo __('reading_val'); ?></label>
                        <input type="number" step="0.01" name="reading_val" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2.5 focus:ring-2 focus:ring-blue-500 outline-none text-lg font-bold">
                    </div>

                    <button type="submit" id="submitBtn" disabled class="w-full bg-gray-400 text-gray-200 p-3 rounded-lg font-bold flex items-center justify-center gap-2 cursor-not-allowed transition">
                        <i data-lucide="send" class="w-5 h-5"></i>
                        <?php echo __('btn_submit'); ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>

    </main>

    <!-- 用户指南弹窗 -->
    <div id="guide-modal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full shadow-2xl p-6 relative border border-gray-100 dark:border-gray-700">
            <button onclick="toggleModal('guide-modal')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <i data-lucide="info" class="text-blue-500"></i>
                <?php echo __('guide_title_user'); ?>
            </h3>
            <div class="text-sm space-y-2 leading-relaxed text-gray-600 dark:text-gray-300">
                <?php echo __('guide_body_user'); ?>
            </div>
            <button onclick="toggleModal('guide-modal')" class="mt-6 w-full bg-blue-600 text-white p-2 rounded font-semibold hover:bg-blue-700 transition">
                <?php echo __('got_it'); ?>
            </button>
        </div>
    </div>

    <!-- 运行 Lucide 图标集及彻底翻译JS中的字符串 -->
    <script>
        // 动态读取来自 PHP 的全翻译词典
        const i18n = <?php echo get_js_translations_json(); ?>;

        lucide.createIcons();

        function toggleModal(id) {
            const modal = document.getElementById(id);
            modal.classList.toggle('hidden');
        }

        function triggerCamera() {
            document.getElementById('cameraInput').click();
        }

        // 文件捕获状态监听，控制提交按钮
        document.getElementById('cameraInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const placeholder = document.getElementById('fileNamePlaceholder');
            const preview = document.getElementById('photoPreview');
            const submitBtn = document.getElementById('submitBtn');

            if (file) {
                placeholder.innerText = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
                
                // 加载预览图
                const reader = new FileReader();
                reader.onload = function(event) {
                    preview.src = event.target.result;
                    preview.classList.remove('hidden');
                }
                reader.readAsDataURL(file);

                // 只有当有图片上传时才允许提交表单
                submitBtn.disabled = false;
                submitBtn.classList.remove('bg-gray-400', 'text-gray-200', 'cursor-not-allowed');
                submitBtn.classList.add('bg-blue-600', 'text-white', 'hover:bg-blue-700');
            } else {
                placeholder.innerText = i18n['no_photo_placeholder'];
                preview.classList.add('hidden');
                submitBtn.disabled = true;
                submitBtn.classList.add('bg-gray-400', 'text-gray-200', 'cursor-not-allowed');
                submitBtn.classList.remove('bg-blue-600', 'text-white', 'hover:bg-blue-700');
            }
        });
    </script>
</body>
</html>