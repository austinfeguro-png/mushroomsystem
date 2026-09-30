"""
=============================================================
BIO-SYNC PREDICTION API  |  prediction_api.py
=============================================================
Fixed: Rate limiting via in-memory token bucket (Issue 4)
       No external library required — works with base Flask
Fixed: /health now returns all loaded model names
Fixed: Graceful fallback when requested algo not found

Keep running while dashboard is open:
    python prediction_api.py

GET /predict?temp=27&hum=85&algo=Random+Forest&stage=fruiting
GET /metrics
GET /health
=============================================================
"""
import json, pickle, time
from collections import defaultdict
from flask import Flask, request, jsonify

app        = Flask(__name__)
models     = {}
ml_results = {}

# ----------------------------------------------------------
# In-memory rate limiter — 60 requests per minute per IP
# Uses a simple sliding window counter
# ----------------------------------------------------------
_rate_store    = defaultdict(list)
RATE_LIMIT     = 60    # max requests
RATE_WINDOW    = 60    # per 60 seconds

def is_rate_limited(ip: str) -> bool:
    now      = time.time()
    window   = now - RATE_WINDOW
    # Remove timestamps outside the window
    _rate_store[ip] = [t for t in _rate_store[ip] if t > window]
    if len(_rate_store[ip]) >= RATE_LIMIT:
        return True
    _rate_store[ip].append(now)
    return False

# Stage thresholds for context (mirrors api_update.php)
STAGE_THRESHOLDS = {
    'colonization': {'min_temp':24,'max_temp':28,'min_hum':70,'max_hum':85},
    'pinning'     : {'min_temp':18,'max_temp':24,'min_hum':85,'max_hum':95},
    'fruiting'    : {'min_temp':24,'max_temp':28,'min_hum':80,'max_hum':95},
}

# ----------------------------------------------------------
# Load models on startup
# ----------------------------------------------------------
try:
    with open('biosync_models.pkl','rb') as f:
        models = pickle.load(f)
    print(f"[OK] Models loaded: {list(models.keys())}")
except FileNotFoundError:
    print("[ERROR] biosync_models.pkl not found. Run train_models.py first.")

try:
    with open('ml_results.json','r') as f:
        ml_results = json.load(f)
    print(f"[OK] ml_results.json loaded. Best: {ml_results.get('best_model','?')}")
except FileNotFoundError:
    print("[WARNING] ml_results.json not found. Run train_models.py first.")


# ----------------------------------------------------------
# /predict
# ----------------------------------------------------------
@app.route('/predict')
def predict():
    ip = request.remote_addr or '0.0.0.0'
    if is_rate_limited(ip):
        return jsonify({'error': 'Rate limit exceeded. Max 60 requests/minute.'}), 429

    if not models:
        return jsonify({'error': 'No models loaded. Run train_models.py first.'}), 500

    # Validate parameters
    try:
        temp  = float(request.args.get('temp', 0))
        hum   = float(request.args.get('hum',  0))
    except (ValueError, TypeError):
        return jsonify({'error': 'Invalid temp or hum value.'}), 400

    # Input range guard — same as api_update.php
    if temp < -10 or temp > 60:
        return jsonify({'error': f'Temperature {temp} out of valid range (-10 to 60).'}), 400
    if hum < 0 or hum > 100:
        return jsonify({'error': f'Humidity {hum} out of valid range (0 to 100).'}), 400

    algo  = request.args.get('algo',  'Random Forest')
    stage = request.args.get('stage', 'fruiting')

    # Graceful fallback if algo not found
    model = models.get(algo)
    if model is None:
        best  = ml_results.get('best_model', 'Random Forest')
        model = models.get(best) or list(models.values())[0]
        algo  = best

    condition  = model.predict([[temp, hum]])[0]
    proba      = model.predict_proba([[temp, hum]])[0]
    confidence = round(float(max(proba)) * 100, 2)

    # Stage threshold context
    t = STAGE_THRESHOLDS.get(stage, STAGE_THRESHOLDS['fruiting'])

    return jsonify({
        'condition'    : condition,
        'confidence'   : confidence,
        'algorithm'    : algo,
        'stage'        : stage,
        'temp'         : temp,
        'hum'          : hum,
        'stageMinTemp' : t['min_temp'],
        'stageMaxTemp' : t['max_temp'],
        'stageMinHum'  : t['min_hum'],
        'stageMaxHum'  : t['max_hum'],
    })


# ----------------------------------------------------------
# /metrics
# ----------------------------------------------------------
@app.route('/metrics')
def metrics():
    if not ml_results:
        return jsonify({'error': 'ml_results.json not found.'}), 500
    return jsonify(ml_results)


# ----------------------------------------------------------
# /health
# ----------------------------------------------------------
@app.route('/health')
def health():
    return jsonify({
        'status'        : 'running',
        'models_loaded' : list(models.keys()),
        'best_model'    : ml_results.get('best_model', 'Unknown'),
        'total_samples' : ml_results.get('total_samples', 'N/A'),
        'imbalanced'    : ml_results.get('imbalanced', False),
    })


if __name__ == '__main__':
    print("\n==============================")
    print("  Bio-Sync Prediction API")
    print("  http://localhost:5000")
    print("  Rate limit: 60 req/min")
    print("  CTRL+C to stop")
    print("==============================\n")
    app.run(host='0.0.0.0', port=5000, debug=False)
