<?php
require_once 'db.php';

$error_msg = '';
$success_msg = '';

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

// ----------------- 获取可用电表列表（支持配对隔离功能） -----------------
$meters_list = [];
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        $meters_list = $pdo->query("SELECT * FROM meters ORDER BY meter_name ASC")->fetchAll();
    } else {
        $p_stmt = $pdo->prepare("
            SELECT m.* 
            FROM meters m
            JOIN user_meters um ON m.id = um.meter_id
            WHERE um.user_id = ?
            ORDER BY m.meter_name ASC
        ");
        $p_stmt->execute([$_SESSION['user_id']]);
        $meters_list = $p_stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" class="<?php echo ($theme === 'dark') ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('app_title'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100 min-h-screen transition-colors duration-200">

    <header class="bg-white dark:bg-gray-800 shadow p-4 sticky top-0 z-40">
        <div class="max-w-4xl mx-auto flex flex-wrap items-center justify-between gap-4">
            <h1 class="font-bold text-lg tracking-tight flex items-center gap-2">
                <i data-lucide="zap" class="text-yellow-500 fill-yellow-500"></i>
                <?php echo __('app_title'); ?>
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
                        <div class="relative">
                            <input type="password" name="password" id="loginPassword" required class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2 pr-10 focus:ring-2 focus:ring-blue-500 outline-none">
                            <button type="button" onclick="togglePasswordVisibility()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                                <i data-lucide="eye" id="passwordEyeIcon" class="w-5 h-5"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-blue-600 text-white p-3 rounded font-bold hover:bg-blue-700 transition flex items-center justify-center gap-2">
                        <i data-lucide="check" class="w-5 h-5"></i>
                        <?php echo __('btn_login'); ?>
                    </button>
                </form>
            </div>

        <?php else: ?>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 border border-gray-100 dark:border-gray-700">
                <div class="flex justify-between items-center mb-6">
                    <div class="text-sm font-semibold">
                        Hi, <span class="text-blue-500 font-bold"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                        <span class="text-xs bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded ml-1"><?php echo ($_SESSION['role'] === 'admin') ? __('admin') : __('user'); ?></span>
                    </div>
                    <?php if($_SESSION['role'] === 'admin'): ?>
                        <a href="admin.php" class="text-xs bg-purple-600 text-white px-3 py-1.5 rounded hover:bg-purple-700 transition font-bold flex items-center gap-1">
                            <i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i>
                            <?php echo __('dashboard'); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if(empty($meters_list)): ?>
                    <div class="bg-yellow-50 dark:bg-yellow-950/30 border border-yellow-200 dark:border-yellow-800 text-yellow-700 dark:text-yellow-300 p-4 rounded text-xs text-center font-semibold">
                        <i data-lucide="alert-triangle" class="w-8 h-8 mx-auto mb-2 text-yellow-500"></i>
                        <?php echo __('no_assigned_meters'); ?>
                    </div>
                <?php else: ?>
                    <form method="POST" enctype="multipart/form-data" id="readingForm">
                        <input type="hidden" name="submit_reading" value="1">
                        
                        <!-- 手写模糊搜索电表组件 (Custom Searchable Combobox) -->
                        <div class="mb-5 relative">
                            <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5"><?php echo __('select_meter'); ?></label>
                            
                            <div class="relative" id="combobox-wrapper">
                                <div class="flex items-center">
                                    <input type="text" 
                                           id="combobox-search" 
                                           placeholder="<?php echo __('combobox_placeholder'); ?>" 
                                           class="w-full bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded p-2.5 pr-10 focus:ring-2 focus:ring-blue-500 outline-none font-semibold text-sm">
                                    <span class="absolute right-3 text-gray-400">
                                        <i data-lucide="chevron-down" class="w-5 h-5"></i>
                                    </span>
                                </div>
                                <input type="hidden" name="meter_id" id="combobox-value" required>
                                <div id="combobox-dropdown" class="absolute left-0 right-0 mt-1 max-h-56 overflow-y-auto bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl hidden z-50">
                                    <!-- JS 渲染选项 -->
                                </div>
                            </div>
                        </div>

                        <!-- 实时拍照区 -->
                        <div class="mb-5">
                            <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5"><?php echo __('photo'); ?></label>
                            <div class="flex flex-col items-center justify-center border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-4 bg-gray-50 dark:bg-gray-700/50">
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
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </main>

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

    <script>
        const i18n = <?php echo get_js_translations_json(); ?>;
        const authorizedMeters = <?php echo json_encode($meters_list); ?>;

        lucide.createIcons();

        function toggleModal(id) {
            const modal = document.getElementById(id);
            modal.classList.toggle('hidden');
        }

        function togglePasswordVisibility() {
            const pwdInput = document.getElementById('loginPassword');
            const eyeIcon = document.getElementById('passwordEyeIcon');
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                eyeIcon.setAttribute('data-lucide', 'eye-off');
            } else {
                pwdInput.type = 'password';
                eyeIcon.setAttribute('data-lucide', 'eye');
            }
            lucide.createIcons();
        }

        function triggerCamera() {
            document.getElementById('cameraInput').click();
        }

        document.getElementById('cameraInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const placeholder = document.getElementById('fileNamePlaceholder');
            const preview = document.getElementById('photoPreview');
            const submitBtn = document.getElementById('submitBtn');

            if (file) {
                placeholder.innerText = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
                const reader = new FileReader();
                reader.onload = function(event) {
                    preview.src = event.target.result;
                    preview.classList.remove('hidden');
                }
                reader.readAsDataURL(file);

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

        // 模糊搜索输入组件交互
        if (authorizedMeters.length > 0) {
            const searchInput = document.getElementById('combobox-search');
            const hiddenValue = document.getElementById('combobox-value');
            const dropdown = document.getElementById('combobox-dropdown');

            function renderDropdown(items) {
                dropdown.innerHTML = '';
                if (items.length === 0) {
                    const emptyItem = document.createElement('div');
                    emptyItem.className = 'p-3 text-xs text-gray-400 italic text-center';
                    emptyItem.innerText = 'No matching meters found.';
                    dropdown.appendChild(emptyItem);
                } else {
                    items.forEach(item => {
                        const option = document.createElement('div');
                        option.className = 'p-2.5 text-xs hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer font-semibold transition flex flex-col';
                        option.innerHTML = `
                            <span>${item.building_name}</span>
                            <span class="text-[10px] text-gray-400 font-normal">${item.meter_name}</span>
                        `;
                        option.addEventListener('mousedown', () => {
                            selectItem(item);
                        });
                        dropdown.appendChild(option);
                    });
                }
            }

            function selectItem(item) {
                searchInput.value = `${item.building_name} (${item.meter_name})`;
                hiddenValue.value = item.id;
                dropdown.classList.add('hidden');
            }

            searchInput.addEventListener('input', (e) => {
                const query = e.target.value.toLowerCase().trim();
                hiddenValue.value = '';
                const filtered = authorizedMeters.filter(item => {
                    return item.meter_name.toLowerCase().includes(query) || 
                           item.building_name.toLowerCase().includes(query);
                });
                renderDropdown(filtered);
                dropdown.classList.remove('hidden');
            });

            searchInput.addEventListener('focus', () => {
                if (searchInput.value === '') {
                    renderDropdown(authorizedMeters);
                }
                dropdown.classList.remove('hidden');
            });

            searchInput.addEventListener('blur', () => {
                setTimeout(() => {
                    dropdown.classList.add('hidden');
                    if (hiddenValue.value === '') {
                        searchInput.value = '';
                    }
                }, 200);
            });
        }
    </script>
</body>
</html>