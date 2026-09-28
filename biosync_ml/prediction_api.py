"""
=============================================================
BIO-SYNC PREDICTION API  |  prediction_api.py
=============================================================
Keep running while dashboard is open:
    python prediction_api.py

GET /predict?temp=27.6&hum=85  ->  condition + confidence
GET /metrics                   ->  algorithm comparison table
GET /health                    ->  status check
=============================================================
"""
import json, pickle
from flask import Flask, request, jsonify

app        = Flask(__name__)
model      = None
ml_results = {}

try:
    with open('biosync_model.pkl','rb') as f: model = pickle.load(f)
    print("[OK] biosync_model.pkl loaded.")
except FileNotFoundError:
    print("[ERROR] biosync_model.pkl not found. Run train_models.py first.")

try:
    with open('ml_results.json','r') as f: ml_results = json.load(f)
    print("[OK] ml_results.json loaded.")
except FileNotFoundError:
    print("[WARNING] ml_results.json not found. Run train_models.py first.")


@app.route('/predict')
def predict():
    if model is None:
        return jsonify({'error': 'Model not loaded. Run train_models.py first.'}), 500
    try:
        temp = float(request.args.get('temp', 0))
        hum  = float(request.args.get('hum',  0))
    except (ValueError, TypeError):
        return jsonify({'error': 'Invalid parameters.'}), 400
    condition  = model.predict([[temp, hum]])[0]
    proba      = model.predict_proba([[temp, hum]])[0]
    confidence = round(float(max(proba)) * 100, 2)
    return jsonify({'condition': condition, 'confidence': confidence, 'temp': temp, 'hum': hum})


@app.route('/metrics')
def metrics():
    if not ml_results:
        return jsonify({'error': 'ml_results.json not found.'}), 500
    return jsonify(ml_results)


@app.route('/health')
def health():
    return jsonify({'status': 'running', 'model_loaded': model is not None})


if __name__ == '__main__':
    print("\n==============================")
    print("  Bio-Sync Prediction API")
    print("  http://localhost:5000")
    print("  CTRL+C to stop")
    print("==============================\n")
    app.run(host='0.0.0.0', port=5000, debug=False)