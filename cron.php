<?php
// 此脚本支持在服务器端通过 CLI（命令行）直接执行：php cron.php
require_once __DIR__ . '/db.php';

// 获取配置截止时间和语言
$deadline = $system_configs['submission_deadline'] ?? '18:00';
$today_date = date('Y-m-d');
$now_time = date('H:i');

echo "Executing TNB Meter missing reports scanner...\n";
echo "Malaysia Current Time: " . date('Y-m-d H:i:s') . "\n";
echo "Configured Deadline: " . $deadline . "\n";

// 1. 获取所有在系统注册的电表
$meters_stmt = $pdo->query("SELECT * FROM meters");
$all_meters = $meters_stmt->fetchAll();

$missing_meters = [];

foreach ($all_meters as $m) {
    // 检查此电表在今天是否有上报记录
    $chk_stmt = $pdo->prepare("SELECT id FROM readings WHERE meter_id = ? AND submitted_date = ?");
    $chk_stmt->execute([$m['id'], $today_date]);
    
    if (!$chk_stmt->fetch()) {
        // 今日未填报
        $missing_meters[] = $m;
    }
}

// 2. 只有在当前时间已经超过管理员配置的截止时间时，才会向通道触发通知
if (strtotime($now_time) >= strtotime($deadline)) {
    if (!empty($missing_meters)) {
        echo "Missing records found. Initiating notifications...\n";
        
        foreach ($missing_meters as $mm) {
            // 根据多语言定义转化提醒
            $alert_text = str_replace(
                ['{meter}', '{date}'], 
                [$mm['building_name'] . ' (' . $mm['meter_name'] . ')', $today_date], 
                __('cron_missing')
            );
            
            // 触发通知通道
            $result = send_alert_notification($alert_text);
            if ($result) {
                echo "Notification successfully dispatched for: " . $mm['meter_name'] . "\n";
            } else {
                echo "Failed to dispatch notification for: " . $mm['meter_name'] . ". Verify credentials.\n";
            }
        }
    } else {
        echo "Perfect. All meters checked and reported for today.\n";
    }
} else {
    echo "Scan Completed: It is currently before the cutoff deadline ($deadline). Alerting skipped.\n";
}