<?php
// =============================================================
// BIO-SYNC ML WIDGET  |  biosync_ml/ml_widget.php
// Included by dashboard.php — uses $temperature and $humidity
// already set by dashboard.php
// =============================================================
require_once __DIR__ . '/../config.php';

// Accept params from GET (AJAX) or dashboard PHP variables
$ml_temp  = isset($_GET['temp'])  ? (float)$_GET['temp']  : (isset($temperature) ? (float)$temperature : 0.0);
$ml_hum   = isset($_GET['hum'])   ? (float)$_GET['hum']   : (isset($humidity)    ? (float)$humidity    : 0.0);
$ml_algo  = isset($_GET['algo'])  ? $_GET['algo']          : ($settings['active_ml_algorithm'] ?? 'Random Forest');
$ml_stage = isset($_GET['stage']) ? $_GET['stage']         : ($settings['current_stage']        ?? 'fruiting');

$stageLabelMap = ['colonization'=>'Colonization','pinning'=>'Pinning Initiation','fruiting'=>'Fruiting'];
$ml_stage_label = $stageLabelMap[$ml_stage] ?? ucfirst($ml_stage);

$predData    = null;
$ml_error    = null;

$ml_api_base = getenv('BIOSYNC_ML_API_URL')
    ?: (defined('BIOSYNC_ML_API_URL') ? BIOSYNC_ML_API_URL : '');
$ml_api_base = rtrim(trim($ml_api_base), '/');

if ($ml_api_base === '') {
    $ml_error = 'ML prediction service URL is not configured.';
} else {
    $query = http_build_query([
        'temp' => $ml_temp,
        'hum' => $ml_hum,
        'algo' => $ml_algo,
        'stage' => $ml_stage,
    ]);
    $ctx = stream_context_create(['http' => [
        'timeout' => 8,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\n",
    ]]);
    $predRaw = @file_get_contents($ml_api_base . '/predict?' . $query, false, $ctx);

    if ($predRaw === false) {
        $ml_error = 'ML prediction service is unreachable or waking up. Please refresh shortly.';
    } else {
        $predData = json_decode($predRaw, true);
        if (!is_array($predData) || isset($predData['error'])) {
            $predData = null;
            $ml_error = 'ML prediction service returned an error. Check the hosted service status.';
        }
    }
}

$palette = [
    'Normal'   => ['class'=>'ml-badge-normal',   'icon'=>'&#9989;'],
    'Warning'  => ['class'=>'ml-badge-warning',  'icon'=>'&#9888;&#65039;'],
    'Critical' => ['class'=>'ml-badge-critical', 'icon'=>'&#128680;'],
];

$condition  = $predData['condition']  ?? 'Unknown';
$confidence = $predData['confidence'] ?? 0;
$style      = $palette[$condition] ?? ['class'=>'ml-badge-normal','icon'=>'&#10067;'];
?>

<style>
.ml-badge {
    border-radius: 10px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    border: 1px solid;
}
.ml-badge-normal   { background: #d1fae5; border-color: #6ee7b7; color: #065f46; }
.ml-badge-warning  { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
.ml-badge-critical { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }

body.dark .ml-badge-normal   { background: #052e16; border-color: #166534; color: #86efac; }
body.dark .ml-badge-warning  { background: #1c1700; border-color: #854d0e; color: #fde68a; }
body.dark .ml-badge-critical { background: #1f0505; border-color: #991b1b; color: #fca5a5; }

.ml-badge-label     { font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .5px; opacity: .8; }
.ml-badge-condition { font-size: 26px; font-weight: 700; }
.ml-badge-meta      { font-size: 12px; margin-top: 6px; opacity: .85; }
.ml-badge-icon      { font-size: 42px; line-height: 1; }

.ml-offline {
    background: var(--bg-body);
    border: 1px solid #fcd34d;
    border-radius: 8px;
    padding: 14px 18px;
    font-size: 13px;
    color: #92400e;
    display: flex;
    align-items: center;
    gap: 10px;
}
body.dark .ml-offline { background: #1c1700; color: #fde68a; border-color: #854d0e; }
</style>

<div class="card-head" style="margin-bottom:14px;">
    <h3 class="card-title">
        <i class="fa-solid fa-brain" style="color:var(--primary);margin-right:6px;"></i>
        ML Condition Prediction
    </h3>
    <div style="text-align:right;">
        <div style="font-size:11px;color:var(--text-muted);">Algorithm: <strong><?= htmlspecialchars($ml_algo) ?></strong></div>
        <div style="font-size:11px;color:var(--text-muted);">Stage: <strong><?= htmlspecialchars($ml_stage_label) ?></strong></div>
    </div>
</div>

<?php if ($ml_error): ?>
    <div class="ml-offline">
        <i class="fa-solid fa-triangle-exclamation fa-lg"></i>
        <span><?= htmlspecialchars($ml_error) ?></span>
    </div>

<?php else: ?>

    <!-- CONDITION BADGE -->
    <div class="ml-badge <?= $style['class'] ?? 'ml-badge-normal' ?>">
        <div>
            <div class="ml-badge-label">Current Condition</div>
            <div class="ml-badge-condition"><?= htmlspecialchars($condition) ?></div>
            <div class="ml-badge-meta">
                Confidence: <strong><?= $confidence ?>%</strong>
                &nbsp;|&nbsp; Temp: <strong><?= $ml_temp ?>&deg;C</strong>
                &nbsp;|&nbsp; Hum: <strong><?= $ml_hum ?>%</strong>
            </div>
        </div>
        <div class="ml-badge-icon"><?= $style['icon'] ?? '&#10067;' ?></div>
    </div>

<?php endif; ?>
