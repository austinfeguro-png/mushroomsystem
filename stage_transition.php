<?php
/**
 * Apply the shared timing, reminder, and audit behavior for a stage change.
 */
function biosync_record_stage_transition(PDO $pdo, string $fromStage, string $toStage, int $userId, string $userName): array
{
    $stageOrder = ['colonization' => 0, 'pinning' => 1, 'fruiting' => 2];
    $stageLabels = [
        'colonization' => 'Colonization',
        'pinning' => 'Pinning Initiation',
        'fruiting' => 'Fruiting',
    ];
    $defaultDays = ['colonization' => 14, 'pinning' => 7, 'fruiting' => 5];
    $stageThresholds = [
        'colonization' => '24–28°C | 70–85% RH',
        'pinning' => '18–24°C | 85–95% RH',
        'fruiting' => '24–28°C | 80–95% RH',
    ];

    if (!isset($stageOrder[$fromStage], $stageOrder[$toStage]) || $fromStage === $toStage) {
        return ['stageChanged' => false, 'early' => false];
    }

    $timingStmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings
        WHERE setting_key IN ('stage_started_at', 'stage_expected_days')");
    $timing = $timingStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $startedAt = strtotime($timing['stage_started_at'] ?? '');
    $expectedDays = (int)($timing['stage_expected_days'] ?? $defaultDays[$fromStage]);
    if ($expectedDays <= 0) {
        $expectedDays = $defaultDays[$fromStage];
    }
    $daysPassed = $startedAt !== false
        ? max(0, (int)floor((time() - $startedAt) / 86400))
        : 0;
    $isNextStage = $stageOrder[$toStage] === $stageOrder[$fromStage] + 1;
    $isEarly = $isNextStage && ($startedAt === false || $daysPassed < $expectedDays);

    $settingStmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_user_id)
        VALUES (:key, :value, :user_id)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_user_id = VALUES(updated_by_user_id)");
    $settingStmt->execute([
        'key' => 'stage_started_at',
        'value' => date('Y-m-d H:i:s'),
        'user_id' => $userId,
    ]);
    $settingStmt->execute([
        'key' => 'stage_expected_days',
        'value' => $defaultDays[$toStage],
        'user_id' => $userId,
    ]);

    // Resolve the prior reminder while retaining it in notification history.
    $pdo->exec("UPDATE notifications SET is_read = 1 WHERE type = 'stage_reminder'");

    if ($isEarly) {
        $title = 'Stage Advanced Early';
        $description = "{$userName} advanced from {$stageLabels[$fromStage]} to {$stageLabels[$toStage]} early on day {$daysPassed} of {$expectedDays} after inspection. New thresholds: {$stageThresholds[$toStage]}.";
    } elseif ($isNextStage) {
        $title = 'Stage Advanced';
        $description = "{$userName} confirmed the stage transition from {$stageLabels[$fromStage]} to {$stageLabels[$toStage]} after inspection. New thresholds: {$stageThresholds[$toStage]}.";
    } else {
        $title = 'Stage Changed';
        $description = "{$userName} changed the stage from {$stageLabels[$fromStage]} to {$stageLabels[$toStage]}. The timer was reset and the stage thresholds were updated to {$stageThresholds[$toStage]}.";
    }

    $pdo->prepare("INSERT INTO notifications (action_by_user_id, title, description, message, type, icon_class, is_read, created_at)
        VALUES (:user_id, :title, :description, :message, 'info', 'fa-seedling', 0, NOW())")
        ->execute([
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'message' => "System stage changed from {$stageLabels[$fromStage]} to {$stageLabels[$toStage]}.",
        ]);

    return ['stageChanged' => true, 'early' => $isEarly];
}
