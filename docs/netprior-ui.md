# NetPrior UI/UX design system and responsive verification

The advanced UI/UX requirements are implemented across the application shell, authentication, infrastructure, assessments, AHP, rankings, reporting, administration and profile screens. The implementation uses the existing Blade, Tailwind and Alpine dependencies. No frontend framework, chart package or icon dependency was added.

## Shared visual system

| Element | Convention |
| --- | --- |
| Navigation | Deep slate/navy, restrained blue active state, consistent line icons |
| Content | Slate canvas, white panels, subtle borders, 12px corner radius, minimal shadows |
| Typography | System sans fallback; compact metadata, readable body text, clear page and section headings |
| Primary action | Blue, text label, minimum 44px height |
| Secondary action | Neutral bordered button |
| Destructive action | Red with a descriptive consequence confirmation |
| Status | Text and semantic color, never color alone |
| Fields | Shared labels/help/errors, required state, 44px controls, visible focus, 16px text on narrow screens |
| Motion | Short transitions; reduced-motion preference respected |
| Content width | Shared maximum workspace width; constrained long forms |

Reusable components include page-header, panel, icon, field, alert, empty-state, stat-card, status-badge, priority, bar and confirm-dialog. Existing Breeze input/button components now follow the same visual system.

`resources/css/app.css` owns the component styling and responsive/print rules. `resources/js/ui.js` registers the Alpine workflows and enhances tables/forms. Tailwind scans both Blade and JavaScript, so dynamically enhanced component classes are retained in production builds.

## Shell, navigation and accessibility

The shell provides a persistent desktop sidebar, grouped collapsible navigation, a sticky top bar with context and profile/role dropdown, page descriptions, role-aware actions and deep-page breadcrumbs. Active states include asset detail/edit and ranking detail pages rather than only their indexes. Top-level pages avoid unnecessary breadcrumbs. There are no invented notification counters.

The mobile/tablet drawer has a clear trigger and close action, an overlay, Escape support, keyboard focus trapping, focus restoration, background scroll locking and inert background content. Hidden navigation cannot receive focus. The drawer closes when following a navigation link or moving to desktop size. A skip link leads to main content. The account dropdown supports Escape and returns focus to its trigger.

Fields associate help/error text with aria-describedby and flag invalid state. A focusable error summary links to existing controls, opens ancestor disclosures, and routes pairwise errors to the comparison editor. General errors remain readable text rather than broken links. Success feedback is consistently announced and can be dismissed explicitly; it does not disappear before a user can read it. Profile status codes are presented as friendly messages.

## Tables and filters

Ordinary record tables are progressively enhanced into labeled cards below 640px. Desktop tables retain their normal structure and headers. Semantic table/row/cell roles and header scopes remain available, while duplicate mobile labels are hidden from assistive technology. Empty states span the record area and provide context or a permitted next action.

Mathematical matrices retain their table structure and horizontal scrolling because the row/column relationships are essential. Those regions are keyboard-focusable and labeled. Mobile filters are collapsed disclosures, so users can see records without scrolling through a long filter form; desktop filters are initially expanded. The controls remain usable without that enhancement. Print styles remove filters and application navigation and restore table layout.

Ranking filters include priority, historical location and asset type. Sorting supports rank, full-precision score, asset name and location. These queries use the preserved snapshot values, so source renames do not change historical filtering. The dashboard assessment-queue link now includes both unassessed and overdue eligible assets, matching the displayed count.

## Dashboard and decision workflows

The dashboard uses four compact KPIs, a latest-run summary, priority/type charts, top upgrade priorities, recent assessments/activity, location distribution and accepted weights. Mobile ordering moves top priorities above secondary charts. Data remains database-backed. Activity details are restricted to authorized audit viewers; other viewers see completed review activity. Bars include numeric values, units, focus/hover tooltips, and meaningful empty states.

AHP compares two criteria at a time using three plain-language preferences and a labeled importance-strength selector. Alpine converts those choices into the original validated Saaty values, including reciprocals. Answered/remaining counts, a progress bar, previous/next controls and an editable review list make completion explicit. Incomplete judgments are not submitted. A no-JavaScript select fallback remains available. CI/CR/eigenvector calculations and acceptance rules are unchanged.

The consistency panel explicitly identifies the saved configuration and never hides an inconsistent CR. Calculated/provisional weights are shown numerically and as bars. Raw matrices and technical metrics are placed in an optional disclosure. Accepted weights are required by the existing backend before a run can execute.

Assessment forms show asset identity, location, manufacturer/model and installation context. Criteria are separated into sections, with one-click 1–5 choices, visible score meanings, retained decimal entry, directional guidance, optional evidence disclosures and completion counts. Draft and complete actions remain distinct. Completing an assessment requires confirming that its record will be locked. The selected action value is preserved when its button enters the loading state.

Asset creation/editing separates basic identity, identification, network information, deployment, financial information and lifecycle notes. Saving a deactivation/retirement requires a consequence-specific confirmation. User/master-data deactivation and record deletion use the same shared confirmation system.

Ranking pages emphasize rank, score, priority, asset context and a concise recommendation preview. View Score Breakdown opens contribution cards showing score, weight and points, followed by a readable recommendation. Detailed calculation and technical evidence remain available to authorized viewers without dominating the management view.

## Submission and destructive-action behavior

The native dialog provides modal semantics, labeled consequences, safe initial focus on Cancel and Escape/cancel behavior. Cancel never submits the pending form. Confirm invokes its original submit action once.

POST/PATCH/DELETE forms show a loading label and spinner, announce progress, disable submit controls and reject duplicate submissions. Assessment draft/completed submitter values are copied to a hidden field before their buttons are disabled, avoiding the common loss-of-action bug. Returning through the browser back cache restores button labels and enabled states. Running prioritization and calculating weights use specific progress labels. These UI guards complement the existing transactional services and authorization.

## Verification

The backend suite passes **94 tests and 793 assertions**, including all existing decision/security tests and new tests for historical ranking filters/sorting and dashboard assessment-queue accuracy. Production Vite build, Blade compilation and PHP formatting pass. No schema changes were required for this UI work.

The local MySQL integration script renders **47 screen/state fixtures**, including data lists/forms, all seven reports, profile and authentication pages, AHP answered/unanswered states, empty inventory, validation feedback and success feedback. All fixture writes are rolled back, and original application table counts are checked afterward. Fixture HTML/screenshots live only under ignored storage/app/qa, not in public production views.

Headless Chrome checks cover **375, 768, 1024, 1366 and 1920px** viewports: **235 screen/viewport checks**. Automated checks cover unintended page overflow and JavaScript exceptions. Screenshots were reviewed at mobile/tablet/desktop sizes; matrices intentionally retain their own scrolling region. Interaction checks cover:

- Drawer opening, collapsed state, focus trap/restoration, Escape and background scroll locking.
- Mobile cards and desktop-only table label behavior.
- AHP completion guard, preference/strength conversion, reciprocals and submission loading.
- Assessment quick scoring and draft/completed action preservation.
- Confirmation safe focus, cancellation and asset-retirement protection.
- Duplicate-submission suppression.
- Field/error associations and reduced-motion behavior.
- Print removal of navigation, filter controls and sidebar offset.

Commands:

```powershell
php artisan test --compact
php artisan view:cache
npm.cmd run build
php scripts/verify-mysql.php
node scripts/verify-layout.mjs
```

Detailed machine results are in storage/app/qa/layout-results.json. This is automated Chrome and visual QA, not a claim of exhaustive assistive-technology or cross-browser certification. Mathematical services, role rules and historical snapshots retain their existing behavior.
## Decision screen refinements

The dashboard keeps freshness details collapsed initially and shows top upgrade priorities before charts at every viewport. Demo context remains visible; its dashboard wording is shorter. Ranking rows emphasize scores and use “Why this rank?” to open the preserved explanation, while dashboard rows include the recommended next step.

AHP highlights the two criteria being compared and announces the selected preference and strength in plain language. These descriptions do not change numerical judgments or reciprocal calculations. Shared fields render a consistent required marker with an accessible required description. Master-data and account/role forms use the same Cancel/Save action layout.

Long assessment, asset and comparison forms retain sticky actions on mobile, with safe-area padding and scroll margins for focused controls. Browser checks verify that the assessment score field remains above the action area.

Print styles expand disclosure content, preserve demo warnings, repeat table headings, allow long tables to span pages, and keep rows together where possible. Native printing opens disclosures temporarily and restores their prior state after printing. Browser checks verify exclusion explanations are visible in print while filters and navigation remain hidden.
