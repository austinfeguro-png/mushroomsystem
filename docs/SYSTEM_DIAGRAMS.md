# BIO-SYNC — System Diagrams

Mermaid diagrams of the Bio-Sync mushroom-growing monitoring & automation system (stage-based thresholds). GitHub renders these blocks natively.

---

## 1. System Flowchart — Data & Control Flow

```mermaid
flowchart TB
    subgraph HW["Grow Room (Arduino + ESP8266)"]
        DHT["DHT22 Sensor<br/>temp + humidity"]
        UNO["Arduino Uno<br/>sensor + actuator control"]
        ESP["ESP8266 NodeMCU<br/>WiFi bridge"]
        FAN["Fan relay input<br/>Uno D4"]
        PUMP["Pump relay input<br/>Uno D5"]
        LED["Onboard fault indicator<br/>Uno D13"]
    end

    subgraph WEB["Web Layer (XAMPP / PHP)"]
        API["api_update.php<br/>validate + log + decide"]
        DB[("MariaDB<br/>biosync_db")]
        LIVE["live_data.php<br/>JSON feed"]
        DASH["dashboard.php<br/>control center"]
        OTHER["historical / notifications /<br/>login / profile / users"]
        CFG["config.php<br/>getDB() credentials"]
        MLW["ml_widget.php"]
    end

    subgraph ML["ML Service (Flask :5000)"]
        TRAIN["train_models.py<br/>(run manually)"]
        PKL[("biosync_models.pkl<br/>3 models")]
        FLASK["prediction_api.py<br/>/predict /metrics /health"]
    end

    DHT --> UNO
    UNO <-->|"Serial link"| ESP
    UNO --> FAN
    UNO --> PUMP
    UNO --> LED
    ESP -->|"Authenticated POST temp + hum (every 10 s)"| API
    API -->|"reads current_stage + thresholds"| DB
    API -->|"sensor_logs (value, timestamp, stage)"| DB
    API -->|"devices.current_state<br/>Temp/Hum/Fan/Pump"| DB
    API -->|"notifications (alert, stage_reminder)"| DB
    API -->|"CMD:FAN=n,PUMP=n + stage thresholds"| ESP

    DB -.-> CFG
    DASH -->|"poll every 3s"| LIVE
    LIVE --> DB
    DASH -->|"stage switch, Extend/Advance,<br/>settings"| DB
    DASH -->|"includes"| MLW
    MLW -->|"/predict (8 s timeout)"| FLASK
    OTHER --> DB

    DB -->|"paired sensor history"| TRAIN
    TRAIN --> PKL
    PKL --> FLASK
```

### Hardware command mapping (from `CMD:FAN=n,PUMP=n`)

| Command | Meaning | Hardware response |
|---|---|---|
| `FAN=1` | Auto: temperature above max or humidity above max. Manual: requested ON. No low-temperature heating action. | Uno D4 asserted LOW (active-low relay input) |
| `FAN=0` | Auto thresholds do not request cooling, or Manual request is OFF; failsafe also forces OFF. | Uno D4 HIGH (relay off) |
| `PUMP=1` | Auto: humidity below stage minimum. Manual: requested ON. | Uno D5 asserted LOW only when sensor/command are valid and pump runtime/cooldown allow it |
| `PUMP=0` | Not requested, humidity above stage maximum, failsafe, runtime limit, or cooldown. | Uno D5 HIGH (relay off) |

The Uno enforces a 10-second maximum continuous pump run and a 60-second cooldown
after every stop. Relay input polarity must be verified with the actual module.

---

## 2. Sequence Diagram — One ESP8266 Reading Cycle

```mermaid
sequenceDiagram
    autonumber
    participant DHT as DHT22
    participant UNO as Arduino Uno
    participant ESP as ESP8266
    participant API as api_update.php
    participant DB as biosync_db
    participant NOTIF as notifications
    participant SET as system_settings

    DHT->>UNO: sample temp + humidity every 3 s
    UNO->>ESP: DATA:TEMP, HUM and applied output STATE
    ESP->>API: HTTPS POST temp=27.4&hum=86.2 + X-Device-Token
    API->>API: verify token + validate numeric ranges (-10..60 C, 0..100 %)
    alt missing token or invalid values
        API-->>ESP: CMD:FAN=0,PUMP=0 (safe state)
    end
    API->>SET: read current_stage + {stage}_min/max_temp, _min/max_hum
    SET-->>API: e.g. colonization: 24-28 C, 70-85 %
    opt first request and every ten minutes
        API->>DB: INSERT sensor_logs (temp and humidity, timestamp, stage)
    end
    API->>DB: UPDATE devices (Temp Node, Hum Node)
    API->>API: fan = temp outside band OR hum>max<br/>pump = hum<min
    API->>DB: UPDATE devices (Fan, Pump)
    alt any threshold breached
        API->>NOTIF: last alert > 5 min ago?
        NOTIF-->>API: yes
        API->>NOTIF: INSERT alert (High/Low Temp, Low/High Hum, or Critical)
    end
    opt stage duration reached
        API->>SET: days_passed >= stage_expected_days?
        API->>NOTIF: INSERT one stage_reminder per stage period
    end
    API-->>ESP: CMD:FAN=1,PUMP=0 + active thresholds
    ESP->>UNO: forward complete validated CMD
    UNO->>UNO: apply thresholds, failsafes, pump runtime and cooldown
    UNO->>ESP: STATE:FAN=n,PUMP=n
```

## 3. Sequence Diagram — Dashboard Poll + ML Prediction (every 3 s)

```mermaid
sequenceDiagram
    autonumber
    participant U as Admin (browser)
    participant DASH as dashboard.php
    participant LIVE as live_data.php
    participant DB as biosync_db
    participant FLASK as Flask :5000

    U->>DASH: open dashboard (session check)
    DASH->>DB: settings, stage, chart data, notifications
    DASH-->>U: rendered page (incl. ML widget)

    loop every 3 seconds
        U->>LIVE: fetch live_data.php
        LIVE->>DB: settings + devices + sensor_logs + notifications
        DB-->>LIVE: rows
        LIVE-->>U: JSON (values, fan/pump, stage, alerts)
        U->>U: repaint cards, LEDs, banner, stage progress
    end

    opt admin changes stage
        U->>DASH: POST save_settings (stage whitelist)
        DASH->>DB: upsert current_stage, stage_started_at, expected_days
    end

    note over U,FLASK: ML widget calls Flask at render time
    DASH->>FLASK: /predict?temp&hum&algo&stage (2 s timeout)
    FLASK-->>DASH: {condition, confidence} or fallback rules
    DASH->>FLASK: /metrics
    FLASK-->>DASH: best_model + accuracy/precision/recall
    DASH-->>U: Normal / Warning / Critical badge
```

## 4. ML Training Flow (manual step)

```mermaid
flowchart LR
    A[("sensor_logs<br/>stage-tagged rows")] -->|"SQL nearest-match pairing<br/>±60 s window, 1 humidity per temp"| B[train_models.py]
    B -->|"label vs stage thresholds<br/>Normal / Warning / Critical"| C{"imbalanced > 5x?"}
    C -->|yes| D["class_weight=balanced<br/>(KNN noted as biased)"]
    C -->|no| E[train all 3 models]
    D --> E
    E --> F[("biosync_models.pkl")]
    E --> G[("ml_results.json<br/>best_model + metrics")]
    F --> H[prediction_api.py :5000]
    G --> H
```

---

**Stage thresholds used everywhere (RRL Table 1):**

| Stage | Temp | Humidity | Default days |
|---|---|---|---|
| Colonization | 24–28 °C | 70–85 % | 14 |
| Pinning Initiation | 18–24 °C | 85–95 % | 7 |
| Fruiting | 24–28 °C | 80–95 % | 5 |
