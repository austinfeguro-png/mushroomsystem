-- One-time migration for the revised Bio-Sync ERD.
-- Backfills existing test records to the SPECTRE account, as requested.
-- Run this against the existing biosync_db database before deploying the PHP changes.

USE biosync_db;

SET @spectre_user_id := (
    SELECT id FROM users WHERE username = 'SPECTRE' LIMIT 1
);
SELECT @spectre_user_id AS backfill_user_id;

-- Notifications can be attributed to a user action and linked to their source.
ALTER TABLE notifications
    ADD COLUMN action_by_user_id INT NULL AFTER id,
    ADD COLUMN device_id INT NULL AFTER action_by_user_id,
    ADD COLUMN sensor_log_id INT NULL AFTER device_id,
    ADD KEY idx_notifications_actor (action_by_user_id),
    ADD KEY idx_notifications_device (device_id),
    ADD KEY idx_notifications_sensor_log (sensor_log_id);

-- Attribute existing user-driven stage actions to the test account.
UPDATE notifications
SET action_by_user_id = @spectre_user_id
WHERE action_by_user_id IS NULL
  AND title IN ('Stage Advanced', 'Stage Extended', 'Stage Progress Adjusted');

-- Link old alert rows to their source device only when the message identifies
-- exactly one sensor category. Historical sensor_log_id stays NULL because the
-- old alerts cannot be reliably tied to a specific stored reading.
UPDATE notifications n
SET device_id = CASE
    WHEN LOWER(CONCAT_WS(' ', n.title, n.description, n.message)) LIKE '%temperature%'
     AND LOWER(CONCAT_WS(' ', n.title, n.description, n.message)) NOT LIKE '%humidity%'
        THEN (SELECT id FROM devices WHERE device_name = 'Temperature Node 1' LIMIT 1)
    WHEN LOWER(CONCAT_WS(' ', n.title, n.description, n.message)) LIKE '%humidity%'
     AND LOWER(CONCAT_WS(' ', n.title, n.description, n.message)) NOT LIKE '%temperature%'
        THEN (SELECT id FROM devices WHERE device_name = 'Humidity Node 1' LIMIT 1)
    ELSE NULL
END
WHERE n.device_id IS NULL AND n.type = 'alert';

-- Convert control rows from device-name matching to a device foreign key.
ALTER TABLE system_controls
    ADD COLUMN device_id INT NULL AFTER id,
    ADD COLUMN changed_by_user_id INT NULL AFTER device_id;

UPDATE system_controls sc
JOIN devices d
  ON (LOWER(sc.device_name) IN ('fan', 'ventilation fan') AND d.device_name = 'Ventilation Fan')
  OR (LOWER(sc.device_name) IN ('pump', 'water pump') AND d.device_name = 'Water Pump')
SET device_id = d.id,
    changed_by_user_id = @spectre_user_id;

-- Keep the current fan and pump control rows present even if an older database
-- did not contain one of the seeded rows.
INSERT INTO system_controls (device_name, status, updated_at)
SELECT d.device_name, IF(d.current_state = '1', 'ON', 'OFF'), CURRENT_TIMESTAMP
FROM devices d
WHERE d.device_name IN ('Ventilation Fan', 'Water Pump')
  AND NOT EXISTS (
      SELECT 1 FROM system_controls sc
      WHERE LOWER(sc.device_name) = LOWER(d.device_name)
         OR (d.device_name = 'Ventilation Fan' AND LOWER(sc.device_name) = 'fan')
         OR (d.device_name = 'Water Pump' AND LOWER(sc.device_name) = 'pump')
  );

UPDATE system_controls sc
JOIN devices d
  ON (LOWER(sc.device_name) IN ('fan', 'ventilation fan') AND d.device_name = 'Ventilation Fan')
  OR (LOWER(sc.device_name) IN ('pump', 'water pump') AND d.device_name = 'Water Pump')
SET device_id = d.id,
    changed_by_user_id = COALESCE(sc.changed_by_user_id, @spectre_user_id);

-- Ensure every control row maps before converting the relationship to NOT NULL.
SELECT id, device_name
FROM system_controls
WHERE device_id IS NULL;

ALTER TABLE system_controls
    DROP INDEX device_name,
    DROP COLUMN device_name,
    MODIFY COLUMN device_id INT NOT NULL,
    ADD UNIQUE KEY uq_system_controls_device (device_id),
    ADD KEY idx_system_controls_changed_by (changed_by_user_id);

-- Track the last account that updated each setting. Existing settings are
-- attributed to SPECTRE per the project's test-data instruction.
ALTER TABLE system_settings
    ADD COLUMN updated_by_user_id INT NULL AFTER setting_value,
    ADD KEY idx_system_settings_updated_by (updated_by_user_id);

UPDATE system_settings
SET updated_by_user_id = @spectre_user_id
WHERE updated_by_user_id IS NULL;

-- Add the foreign-key relationships. Deleting an actor/source preserves the
-- notification or setting record by clearing its optional reference.
ALTER TABLE notifications
    ADD CONSTRAINT notifications_ibfk_actor
        FOREIGN KEY (action_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT notifications_ibfk_device
        FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE SET NULL,
    ADD CONSTRAINT notifications_ibfk_sensor_log
        FOREIGN KEY (sensor_log_id) REFERENCES sensor_logs (id) ON DELETE SET NULL;

ALTER TABLE system_controls
    ADD CONSTRAINT system_controls_ibfk_device
        FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
    ADD CONSTRAINT system_controls_ibfk_user
        FOREIGN KEY (changed_by_user_id) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE system_settings
    ADD CONSTRAINT system_settings_ibfk_user
        FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL;
