# Batch assessments

Open **Assessments → Batch assessments**. Filter by location, asset type or asset name/code. Each page loads at most 20 non-retired assets, so a larger inventory can be assessed in manageable groups.

1. Expand criterion guidance when needed. Each active criterion has its own 1–5 descriptions and scoring direction; batch entry supports the same one-to-ten criterion configuration as AHP.
2. Select the rows you intend to save. Enter a separate assessment date, criterion scores and optional overall remarks for each asset. Decimal scores are supported. Scores are never copied automatically between assets.
3. Use **Save selected drafts** to preserve partial work. The next page load resumes the newest editable draft for each asset, including its original date and scores. Links open the detailed draft form for measurements or evidence.
4. Select ready rows and choose **Complete selected**. Every active criterion must be scored, dates must be valid, and confirmation is required. Each assessment is completed separately and becomes immutable through the existing service and policies.
5. Continue with another group, or generate a ranking once sufficient assessments are complete. Batch entry does not run AHP or calculate rankings automatically.

Unselected rows are not written. The browser blocks a batch save when unchecked rows contain edits; use **Select rows with changes** and save drafts first. Unsaved-change warnings also protect pagination and navigation. Without JavaScript, select rows manually and save all entered work before completing a smaller selection.

Existing per-criterion measurements, units and technical remarks are preserved when a draft's score changes in the grid. Clearing a score with evidence is rejected; use the detailed draft form to revise or remove that evidence deliberately. Inactive criterion scores already present in drafts remain preserved by the existing assessment service. Completed records are never reopened.

Each save is transactional: an invalid, retired, removed, stale or unauthorized row prevents the whole selected save. The application checks draft content versions and criterion guidance against the loaded page, so a stale tab cannot silently overwrite newer work. A newly created editable draft also requires reloading rather than creating a duplicate.

Page tokens bind the authenticated user, asset group, criteria and editable drafts. They expire after two hours, are consumed after successful saving, and retain at most ten open page contexts per session. Session locking serializes duplicate POSTs. A repeated submission cannot create another set of assessments. No background autosave or spreadsheet import is introduced.

Validation covers separate records, partial drafts, completion, evidence preservation, stale pages, atomic rollback, ownership, scope, expiry, retirement and replay. Local MySQL checks resume a draft within a rolled-back transaction and verify the existing record and evidence are preserved. Browser checks exercise row selection, incomplete-score guards, protection of unchecked edits, action preservation and completion confirmation.
