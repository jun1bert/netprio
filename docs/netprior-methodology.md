# NetPrior decision methodology

## Assessment contract

Each assessment belongs to one asset and evaluator. Scores are rows in assessment_scores, with a unique assessment/criterion pair. A numeric raw_value, raw_unit and evidence remarks can accompany a score. Raw values are evidence; the evaluator supplies the normalized 1–5 rubric score. There is no undocumented conversion from raw measurements to criterion scores.

- Benefit direction: a higher entered score increases upgrade priority. For the starter performance criterion, this measures **performance shortfall**, not good performance.
- Cost direction: a lower entered score increases upgrade priority. For the starter cost criterion, 1 indicates low financial burden and 5 high burden, so affordable upgrades are favored. Institutions should explicitly review whether this policy matches their objectives.

Criterion descriptions contain the evaluator guidance. The initial nonfinancial criteria use 1 = very low need and 5 = very high need. Fractional scores are allowed. All active criteria must be scored before completion. No criteria, missing scores, invalid score bounds, transferred assets, or retired assets prevent completion with validation messages.

Evaluators can edit their own drafts; administrators can edit all drafts. Completed assessments cannot be edited, downgraded or deleted through application routes. Corrections are new assessments. Completion stores criterion names, codes, directions and descriptions with score evidence, preserving the original rubric. Scoring direction cannot be changed through CRUD once scores exist; create a replacement criterion and deactivate the original instead.

## AHP

The hierarchy is the upgrade-prioritization objective, the institution's active criteria, and the assessed network assets. Expert pairwise judgments determine the criterion weights; assessed asset scores supply the alternative evaluations. This is AHP-derived weighting followed by a directional weighted-sum ranking, rather than a separate pairwise comparison of every asset.

The comparison matrix has A[i,i] = 1 and A[j,i] = 1 / A[i,j]. Input accepts Saaty's 1–9 integer values and their reciprocals. Only one canonical pair is stored, with criterion_a_id < criterion_b_id. The application handles the reciprocal automatically. Every active criterion pair is required; missing judgments are not assumed equal.

AhpService calculates column totals and the column-normalized matrix. Normalized row averages initialize a power iteration that converges to the principal eigenvector. Weights sum to one. The service calculates:

```
lambda_max = mean((A × w)[i] / w[i])
CI = max(0, (lambda_max - n) / (n - 1))
CR = CI / RI
```

The supplied Random Index values are used for n = 1…10: 0, 0, 0.58, 0.90, 1.12, 1.24, 1.32, 1.41, 1.45, 1.49. For n = 1 or 2, CR is zero because RI is zero. One criterion receives weight one; zero or more than ten active criteria produce an understandable validation response.

CR ≤ 0.10 is accepted. Inconsistent judgments are saved for review, their calculated CR remains visible, and accepted weight fields are cleared. They cannot be used to run prioritization. A criterion change also clears accepted weights. The ranking service recomputes the current matrix, verifies consistency and compares its weights with the accepted weights before executing a run. Editing a name/description conservatively requires accepting AHP again.

## Asset scoring

For score s in [1,5]:

```
Benefit normalized score = (s - 1) / 4
Cost normalized score    = (5 - s) / 4
Contribution            = normalized score × criterion weight × 100
Final priority score    = sum(contributions)
```

Each contribution is on a 0–100 score basis; all criterion weights together sum to one. This maps the strongest upgrade preference to 100 and weakest preference to zero. Raw measurements do not bypass the rubric. Arithmetic remains in services, not Blade views. Double precision is retained internally and persisted without display rounding; 17-digit numeric strings avoid PDO truncation at reciprocal boundaries. Only negligible floating-point endpoint excess is bounded to [0,100]. Formatting in views rounds for display.

Known tests include a consistent AHP matrix with weights 4/7, 2/7 and 1/7; 3:1 weights produce .75 and .25. A performance score of 5 and cost score of 3 under those weights produce 75 + 12.5 = **87.5**, classified Critical under the default policy.

## Eligibility, selection, ties and thresholds

Eligible asset statuses are active and under_maintenance. Inactive and retired assets are excluded. The latest completed assessment is selected by assessment_date, then greatest ID for equal dates. A newer draft never hides a completed assessment. Assets without a completed assessment or missing any current active criterion are skipped with a saved reason. Inactive location/type records prevent new assignments but do not discard the assessment or ranking history of existing assets.

Scores are sorted descending at full precision. Exactly equal computed scores receive competition ranks (1, 2, 2, 4). Asset code, then asset ID, deterministically orders tied rows. Assets whose scores merely round to the same display value may have different ranks.

Default policy: Critical ≥80, High ≥60, Medium ≥40, Low <40. Settings permit ordered critical > high > medium ≥0 thresholds up to 100. These are institutional policy, not empirically validated thresholds. Each run snapshots its policy, so policy edits affect subsequent runs only.

## Traceability and historical results

The run transaction preserves comparison and normalized matrices, column totals, eigenvector weights, CI/CR/RI, criterion metadata, thresholds, scoring algorithm/version, institution name, eligibility and tie rules, and skipped assets. Each result preserves the selected assessment ID/date/evaluator ID, asset code/name/type/location/status, score evidence, normalized values, weighted contributions, final score, rank, priority and deterministic recommendation.

Recommendations derive from priority level and up to three largest weighted contributors; no external AI service is involved. Management can view rankings, totals, raw measurements and contributions. Technical remarks are restricted to technical viewers. Historical run pages use the snapshots, not recalculated values from edited source records. Completed runs/results have no editing or deletion endpoints. Important operations create audit events in the same transaction.

Referential deletion restrictions protect sources of historical decisions. Users with decision activity cannot delete their accounts; administrators can deactivate access instead. Historical reports select a completed run explicitly or use the latest completed run. Dashboard priority counts describe the latest completed run; asset details also show the latest run that included that particular asset.

NetPrior is a decision support system. Authorized personnel review the evidence, feasibility and institutional context before making final upgrade decisions.