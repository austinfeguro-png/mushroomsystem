"""
=============================================================
BIO-SYNC ML TRAINER  |  train_models.py
=============================================================
RUN ONCE after collecting at least 50 sensor readings:
    python train_models.py

PRODUCES:
    biosync_model.pkl   — best trained model
    ml_results.json     — accuracy / precision / recall table
=============================================================
"""
import json, pickle
import pandas as pd
import mysql.connector
from sklearn.tree            import DecisionTreeClassifier
from sklearn.ensemble        import RandomForestClassifier
from sklearn.neighbors       import KNeighborsClassifier
from sklearn.model_selection import train_test_split
from sklearn.metrics         import accuracy_score, precision_score, recall_score

DB = dict(host='localhost', user='root', password='', database='biosync_db')

MAX_TEMP = 30.0
MIN_HUM  = 85.0
MAX_HUM  = 90.0

print("\n[1/6] Connecting to biosync_db ...")
try:
    conn = mysql.connector.connect(**DB)
    print("      Connected.")
except mysql.connector.Error as e:
    print(f"      FAILED: {e}")
    exit(1)

print("[2/6] Loading sensor data ...")
query = """
    SELECT
        t.recorded_value AS temperature,
        h.recorded_value AS humidity
    FROM sensor_logs t
    JOIN sensor_logs h
      ON DATE_FORMAT(t.recorded_at,'%Y-%m-%d %H:%i') =
         DATE_FORMAT(h.recorded_at,'%Y-%m-%d %H:%i')
    WHERE t.device_id = (SELECT id FROM devices WHERE device_name='Temperature Node 1' LIMIT 1)
      AND h.device_id = (SELECT id FROM devices WHERE device_name='Humidity Node 1'    LIMIT 1)
    ORDER BY t.recorded_at DESC LIMIT 2000
"""
df = pd.read_sql(query, conn)
conn.close()
print(f"      {len(df)} paired readings loaded.")

if len(df) < 50:
    print("\n  [WARNING] Need at least 50 readings. Let Arduino collect more data then retry.")
    exit(1)

print("[3/6] Labelling conditions ...")
def label(row):
    if row['temperature'] > MAX_TEMP:                              return 'Critical'
    if row['humidity'] < MIN_HUM or row['humidity'] > MAX_HUM:    return 'Warning'
    return 'Normal'

df['condition'] = df.apply(label, axis=1)
for lbl, cnt in df['condition'].value_counts().items():
    print(f"        {lbl:<12} {cnt} rows")

X = df[['temperature','humidity']]
y = df['condition']
X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42, stratify=y)

print("[4/6] Training models ...")
models = {
    'Decision Tree': DecisionTreeClassifier(random_state=42),
    'Random Forest': RandomForestClassifier(n_estimators=100, random_state=42),
    'KNN'          : KNeighborsClassifier(n_neighbors=5),
}

results   = {}
trained   = {}
best_name = None
best_acc  = 0.0

print("\n" + "="*58)
print("  ALGORITHM COMPARISON")
print("="*58)
print(f"  {'Algorithm':<20} {'Accuracy':>10} {'Precision':>10} {'Recall':>10}")
print("-"*58)

for name, mdl in models.items():
    mdl.fit(X_train, y_train)
    yp   = mdl.predict(X_test)
    acc  = accuracy_score (y_test, yp)
    prec = precision_score(y_test, yp, average='weighted', zero_division=0)
    rec  = recall_score   (y_test, yp, average='weighted', zero_division=0)
    results[name] = {'accuracy': round(acc*100,2), 'precision': round(prec*100,2), 'recall': round(rec*100,2)}
    trained[name] = mdl
    tag = ' <- best' if acc > best_acc else ''
    print(f"  {name:<20} {acc*100:>9.2f}% {prec*100:>9.2f}% {rec*100:>9.2f}%{tag}")
    if acc > best_acc: best_acc = acc; best_name = name

print("="*58)
print(f"\n  Best Model: {best_name}  ({best_acc*100:.2f}%)")
print("="*58)

print("\n[5/6] Saving biosync_model.pkl ...")
with open('biosync_model.pkl','wb') as f: pickle.dump(trained[best_name], f)

print("[6/6] Saving ml_results.json ...")
with open('ml_results.json','w') as f: json.dump({'best_model': best_name, 'results': results}, f, indent=2)

print("\n[DONE] Run prediction_api.py next.\n")