"""
=============================================================
BIO-SYNC ML TRAINER  |  train_models.py
=============================================================
Fixed: Minute-level JOIN replaced with 60-second window (Bug 6)
Fixed: Class imbalance detection + class_weight='balanced' (Issue 5)
Fixed: Lossy pandas dedup replaced with SQL nearest-match pairing (Bug 8)

RUN ONCE after collecting at least 50 sensor readings:
    python train_models.py

PRODUCES:
    biosync_models.pkl  — all 3 trained models
    ml_results.json     — accuracy / precision / recall table
=============================================================
"""
import json, pickle
import pandas as pd
import mysql.connector
from sklearn.tree            import DecisionTreeClassifier
from sklearn.ensemble        import RandomForestClassifier
from sklearn.neighbors       import KNeighborsClassifier
from sklearn.preprocessing   import StandardScaler
from sklearn.pipeline        import make_pipeline
from sklearn.model_selection import train_test_split
from sklearn.metrics         import accuracy_score, precision_score, recall_score

DB = dict(host='localhost', user='root', password='', database='biosync_db')

# Stage-specific thresholds from RRL Table 1
STAGE_THRESHOLDS = {
    'colonization': {'min_temp':24,'max_temp':28,'min_hum':70,'max_hum':85},
    'pinning'     : {'min_temp':18,'max_temp':24,'min_hum':85,'max_hum':95},
    'fruiting'    : {'min_temp':24,'max_temp':28,'min_hum':80,'max_hum':95},
}
DEFAULT_THRESH = {'min_temp':24,'max_temp':28,'min_hum':80,'max_hum':95}

print("\n[1/7] Connecting to biosync_db ...")
try:
    conn = mysql.connector.connect(**DB)
    print("      Connected.")
except mysql.connector.Error as e:
    print(f"      FAILED: {e}")
    print("      Make sure XAMPP MySQL is running.")
    exit(1)

# ----------------------------------------------------------
# FIX (Bug 6): Use 60-second TIMESTAMPDIFF window instead of
# minute-level DATE_FORMAT match — captures more pairings
# ----------------------------------------------------------
print("[2/7] Loading sensor data (60-second pairing window) ...")
query = """
    SELECT temperature, humidity, stage, recorded_at
    FROM (
        SELECT
            t1.recorded_value                AS temperature,
            t1.recorded_at                   AS recorded_at,
            COALESCE(t1.stage, 'fruiting')   AS stage,
            (
                SELECT h.recorded_value
                FROM sensor_logs h
                WHERE h.device_id = (
                         SELECT id FROM devices
                         WHERE device_name = 'Humidity Node 1' LIMIT 1
                     )
                  AND ABS(TIMESTAMPDIFF(SECOND, t1.recorded_at, h.recorded_at)) <= 60
                ORDER BY ABS(TIMESTAMPDIFF(SECOND, t1.recorded_at, h.recorded_at)) ASC,
                         h.recorded_at ASC
                LIMIT 1
            ) AS humidity
        FROM sensor_logs t1
        WHERE t1.device_id = (
                 SELECT id FROM devices
                 WHERE device_name = 'Temperature Node 1' LIMIT 1
             )
        ORDER BY t1.recorded_at DESC
        LIMIT 2000
    ) AS paired
    WHERE humidity IS NOT NULL
"""
df = pd.read_sql(query, conn)
conn.close()

# FIX (Bug 8): dedup happens in SQL now — each temperature reading is paired
# with exactly ONE humidity reading (nearest timestamp within the 60-second
# window). The old pandas dedup-on-(temperature, stage) call also deleted
# legitimate distinct readings that happened to share a value.
print(f"      {len(df)} paired readings loaded (1 humidity match per temperature reading).")

if len(df) < 50:
    print("\n  [WARNING] Need at least 50 readings. Let Arduino collect more then retry.")
    exit(1)

# ----------------------------------------------------------
# Label conditions — stage-aware
# ----------------------------------------------------------
print("[3/7] Labelling conditions (stage-aware) ...")

def label(row):
    stage = row.get('stage', 'fruiting')
    t = STAGE_THRESHOLDS.get(stage, DEFAULT_THRESH)
    if row['temperature'] > t['max_temp'] or row['temperature'] < t['min_temp']:
        return 'Critical'
    if row['humidity'] < t['min_hum'] or row['humidity'] > t['max_hum']:
        return 'Warning'
    return 'Normal'

df['condition'] = df.apply(label, axis=1)

print("      Label distribution:")
counts = df['condition'].value_counts()
for lbl, cnt in counts.items():
    print(f"        {lbl:<12} {cnt} rows")

print("      Stage distribution:")
for s, cnt in df['stage'].value_counts().items():
    print(f"        {s:<20} {cnt} rows")

if df['condition'].nunique() < 2:
    print("\n  [WARNING] Only one condition class found. Collect more varied readings.")
    exit(1)

# ----------------------------------------------------------
# FIX (Issue 5): Class imbalance detection + balanced weights
# ----------------------------------------------------------
print("[4/7] Checking class balance ...")
majority = counts.max()
minority = counts.min()
ratio    = majority / minority if minority > 0 else 999
use_balanced = ratio > 5

if use_balanced:
    print(f"  [WARNING] Class imbalance detected ({majority}:{minority} ratio = {ratio:.1f}x).")
    print("            Applying class_weight='balanced' to compensate.")
else:
    print(f"      Class balance OK (ratio {ratio:.1f}x — threshold 5x).")

X = df[['temperature','humidity']]
y = df['condition']

X_train, X_test, y_train, y_test = train_test_split(
    X, y, test_size=0.2, random_state=42, stratify=y
)

# ----------------------------------------------------------
# Train all 3 models with optional balanced weighting
# ----------------------------------------------------------
print("[5/7] Training all 3 models ...")
models_to_train = {
    'Decision Tree': DecisionTreeClassifier(
        random_state=42,
        class_weight='balanced' if use_balanced else None
    ),
    'Random Forest': RandomForestClassifier(
        n_estimators=100, random_state=42,
        class_weight='balanced' if use_balanced else None
    ),
    # Scale temperature and humidity so their different units do not bias distance.
    'KNN'          : make_pipeline(StandardScaler(), KNeighborsClassifier(n_neighbors=5)),
}

if use_balanced:
    print("  Note: KNN does not support class_weight — results may be biased toward majority class.")

results   = {}
trained   = {}
best_name = None
best_acc  = 0.0

print("\n" + "="*62)
print("  ALGORITHM COMPARISON")
print("="*62)
print(f"  {'Algorithm':<20} {'Accuracy':>10} {'Precision':>10} {'Recall':>10}")
print("-"*62)

for name, mdl in models_to_train.items():
    mdl.fit(X_train, y_train)
    yp   = mdl.predict(X_test)
    acc  = accuracy_score (y_test, yp)
    prec = precision_score(y_test, yp, average='weighted', zero_division=0)
    rec  = recall_score   (y_test, yp, average='weighted', zero_division=0)

    results[name] = {
        'accuracy' : round(acc *100, 2),
        'precision': round(prec*100, 2),
        'recall'   : round(rec *100, 2),
    }
    trained[name] = mdl

    tag = ' <- best' if acc > best_acc else ''
    print(f"  {name:<20} {acc*100:>9.2f}% {prec*100:>9.2f}% {rec*100:>9.2f}%{tag}")

    if acc > best_acc:
        best_acc  = acc
        best_name = name

print("="*62)
print(f"\n  Best Model : {best_name}  ({best_acc*100:.2f}%)")
print(f"  Imbalanced : {'Yes — class_weight applied' if use_balanced else 'No'}")
print("="*62)

# ----------------------------------------------------------
# Save all 3 models + metadata
# ----------------------------------------------------------
print("\n[6/7] Saving biosync_models.pkl (all 3 models) ...")
with open('biosync_models.pkl','wb') as f:
    pickle.dump(trained, f)

print("[7/7] Saving ml_results.json ...")
with open('ml_results.json','w') as f:
    json.dump({
        'best_model'    : best_name,
        'results'       : results,
        'imbalanced'    : use_balanced,
        'class_balance' : {k: int(v) for k, v in counts.items()},
        'total_samples' : len(df),
    }, f, indent=2)

print("\n[DONE] Run prediction_api.py next.\n")
