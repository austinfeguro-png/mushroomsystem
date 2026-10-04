---
name: biosync-ml-pipeline
description: Train, evaluate, or serve Bio-Sync environmental-condition models and keep the Flask prediction contract aligned.
---

# Bio-Sync ML pipeline

Use for `biosync_ml/` training, inference, model-artifact, or prediction changes. Consult the [project map](../biosync-workflow/references/project-map.md) for API and stage-setting contracts.

- Preserve legitimate distinct readings during deduplication; include growth stage as a feature; scale numeric inputs where model distance or magnitude requires it; validate finite values, stage names, and ranges before inference.
- Guard class counts before stratified splitting/training. Keep evaluation honest and identify labels generated from threshold rules as rule-derived labels, not independent biological ground truth.
- Regenerate both `biosync_models.pkl` and `ml_results.json` after training. Detect stale legacy artifacts and report when retraining is blocked by missing database profile settings or data.
- Use the documented `BIOSYNC_DB_PROFILE` local/online environment settings and a read-only source-data account. Never print or commit database credentials. Do not change `config.php` credentials or schema without separate approval.
- Preserve the API rate-limit policy across prediction routes. Version pinning is a dependency change and requires separate approval; first report the actual runtime versions and compatibility need.

Test preprocessing and prediction contracts for known stages, unknown stages, missing/non-finite values, class imbalance, and stale artifacts. Do not describe threshold-generated labels as validated ground truth.
