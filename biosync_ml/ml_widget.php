<?php
// =============================================================
// BIO-SYNC ML WIDGET  |  biosync_ml/ml_widget.php
// Included by dashboard.php — uses $temperature and $humidity
// already set by dashboard.php
// =============================================================
// Accept temp/hum from GET (AJAX fetch) or from dashboard PHP variables
$ml_temp = isset($_GET['temp'])  ? (float)$_GET['temp']  :
          (isset($temperature)   ? (float)$temperature    : 0.0);
$ml_hum  = isset($_GET['hum'])   ? (float)$_GET['hum']   :
          (isset($humidity)      ? (float)$humidity       : 0.0);

$predData    = null;
$metricsData = null;
$ml_error    = null;

$ctx = stream_context_create(['http' => ['timeout' => 2]]);
$predRaw    = @file_get_contents("http://127.0.0.1:5000/predict?temp={$ml_temp}&hum={$ml_hum}", false, $ctx);
$metricsRaw = @file_get_contents("http://127.0.0.1:5000/metrics", false, $ctx);

if ($predRaw === false || $metricsRaw === false) {
    $ml_error = "ML Prediction API is offline. Open a terminal in the biosync_ml folder and run: python prediction_api.py";
} else {
    $predData    = json_decode($predRaw,    true);
    $metricsData = json_decode($metricsRaw, true);
}

$palette = [
    'Normal'   => ['class'=>'ml-badge-normal',   'icon'=>'&#9989;'],
    'Warning'  => ['class'=>'ml-badge-warning',  'icon'=>'&#9888;&#65039;'],
    'Critical' => ['class'=>'ml-badge-critical', 'icon'=>'&#128680;'],
];

$condition  = $predData['condition']  ?? 'Unknown';
$confidence = $predData['confidence'] ?? 0;
$bestModel  = $metricsData['best_model'] ?? 'N/A';
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

.ml-algo-table { width:100%; border-collapse:collapse; font-size:13px; border-radius:8px; overflow:hidden; }
.ml-algo-table th { padding:10px 16px; font-weight:600; background:var(--primary); color:#fff; }
.ml-algo-table td { padding:10px 16px; color:var(--text-main); border-bottom: 1px solid var(--border-color); }
.ml-algo-table tr:last-child td { border-bottom: none; }
.ml-algo-table tbody tr:nth-child(odd)  { background: var(--card-bg); }
.ml-algo-table tbody tr:nth-child(even) { background: var(--bg-body); }
.ml-algo-table tbody tr:hover           { background: var(--border-color); }
</style>

<div class="card-head" style="margin-bottom:14px;">
    <h3 class="card-title">
        <i class="fa-solid fa-brain" style="color:var(--primary);margin-right:6px;"></i>
        ML Condition Prediction
    </h3>
    <span style="font-size:11px;color:var(--text-muted);">Active Model: <?= htmlspecialchars($bestModel) ?></span>
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

    <!-- ALGORITHM COMPARISON TABLE -->
    <?php if (!empty($metricsData['results'])): ?>
    <div style="font-size:13px;font-weight:600;margin-bottom:10px;color:var(--text-main);">
        Algorithm Performance Comparison
    </div>
    <div style="overflow-x:auto;">
        <table class="ml-algo-table">
            <thead>
                <tr>
                    <th style="padding:10px 16px;text-align:left;font-weight:600;">Algorithm</th>
                    <th style="padding:10px 16px;text-align:center;font-weight:600;">Accuracy</th>
                    <th style="padding:10px 16px;text-align:center;font-weight:600;">Precision</th>
                    <th style="padding:10px 16px;text-align:center;font-weight:600;">Recall</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $rows = ['var(--card-bg)','var(--bg-body)']; $i = 0;
                foreach ($metricsData['results'] as $algo => $m):
                    $isBest = ($algo === $bestModel);
                ?>
                <tr class="<?= $isBest ? 'ml-best-row' : '' ?>" style="<?= $isBest ? 'font-weight:600;' : '' ?>">
                    <td style="padding:10px 16px;color:var(--text-main);">
                        <?= htmlspecialchars($algo) ?>
                        <?php if ($isBest): ?>
                            <span style="background:var(--primary);color:#fff;font-size:10px;
                                         padding:2px 7px;border-radius:10px;margin-left:6px;">Best</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 16px;text-align:center;color:var(--text-main);"><?= $m['accuracy']  ?>%</td>
                    <td style="padding:10px 16px;text-align:center;color:var(--text-main);"><?= $m['precision'] ?>%</td>
                    <td style="padding:10px 16px;text-align:center;color:var(--text-main);"><?= $m['recall']    ?>%</td>
                </tr>
                <?php $i++; endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php endif; ?>