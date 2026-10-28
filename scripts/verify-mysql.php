<?php

use App\Http\Controllers\AhpController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BatchAssessmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\NetworkAssetController;
use App\Http\Controllers\PrioritizationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleManagementController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserManagementController;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssetType;
use App\Models\Criterion;
use App\Models\Location;
use App\Models\NetworkAsset;
use App\Models\PairwiseComparison;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AhpService;
use App\Services\AssessmentService;
use App\Services\AuditService;
use App\Services\PrioritizationService;
use App\Services\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

// Local MySQL integration check. All fixture writes are enclosed in a rolled-back transaction.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment(['local', 'testing']) || DB::getDriverName() !== 'mysql') {
    fwrite(STDERR, 'Run this check only on a local/testing MySQL installation.'.PHP_EOL);
    exit(1);
}
function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
$counts = collect(['users', 'criteria', 'locations', 'asset_types', 'network_assets', 'assessments', 'assessment_scores', 'pairwise_comparisons', 'prioritization_runs', 'prioritization_results', 'audit_logs', 'system_settings'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
DB::beginTransaction();
try {
    $suffix = bin2hex(random_bytes(5));
    $role = Role::firstOrCreate(['code' => 'administrator'], ['name' => 'Administrator']);
    $actor = new User(['name' => 'QA Administrator', 'email' => 'qa-'.$suffix.'@example.test', 'password' => bin2hex(random_bytes(20))]);
    $actor->role()->associate($role);
    $actor->is_active = true;
    $actor->save();
    Auth::setUser($actor);
    $evaluatorRole = Role::firstOrCreate(['code' => 'evaluator'], ['name' => 'Evaluator']);
    $accountService = app(AccountService::class);
    $target = $accountService->save($actor, ['name' => 'QA Evaluator', 'email' => 'qa-evaluator-'.$suffix.'@example.test', 'role_id' => $evaluatorRole->id, 'is_active' => true, 'password' => bin2hex(random_bytes(20))]);
    $accountService->save($actor, ['name' => $target->name, 'email' => $target->email, 'role_id' => $evaluatorRole->id, 'is_active' => false], $target);
    verify(! $target->fresh()->is_active, 'MySQL account deactivation failed.');
    Criterion::query()->update(['is_active' => false, 'weight' => null]);
    $a = Criterion::create(['code' => 'QA_PERF_'.$suffix, 'name' => 'Performance shortfall', 'direction' => 'benefit', 'is_active' => true]);
    $b = Criterion::create(['code' => 'QA_SEC_'.$suffix, 'name' => 'Security exposure', 'direction' => 'benefit', 'is_active' => true]);
    $key = $a->id.'_'.$b->id;
    app(AhpService::class)->save($actor, [$key => 1 / 9]);
    verify(abs(PairwiseComparison::where('criterion_a_id', $a->id)->first()->comparison_value - 1 / 9) < 1e-15, 'MySQL reciprocal precision check failed.');
    app(AhpService::class)->save($actor, [$key => 1]);
    $c = Criterion::create(['code' => 'QA_COND_'.$suffix, 'name' => 'Technical condition', 'direction' => 'benefit', 'is_active' => true]);
    app(AhpService::class)->save($actor, [$key => 1, $a->id.'_'.$c->id => 1, $b->id.'_'.$c->id => 1]);
    $location = Location::create(['name' => 'QA Data Center', 'building' => 'QA campus', 'is_active' => true]);
    $type = AssetType::firstOrCreate(['name' => 'QA managed switch'], ['is_active' => true]);
    foreach ([['Core switch', 4.6], ['Campus firewall', 3.8], ['Library access point', 2.8], ['Laboratory switch', 1.8]] as $i => [$name,$score]) {
        $asset = NetworkAsset::create(['asset_code' => 'QA-'.$suffix.'-'.($i + 1), 'name' => $name, 'asset_type_id' => $type->id, 'location_id' => $location->id, 'status' => 'active']);
        $asset->is_demo = true;
        $asset->save();
        $assessment = app(AssessmentService::class)->save($actor, ['network_asset_id' => $asset->id, 'assessment_date' => today()->toDateString(), 'status' => 'completed', 'scores' => [
            $a->id => ['score' => $score, 'raw_value' => 92, 'raw_unit' => '%', 'remarks' => 'Isolated QA fixture; not an institutional assessment.'],
            $b->id => ['score' => $score, 'remarks' => 'Isolated QA fixture.'],
            $c->id => ['score' => $score, 'remarks' => 'Isolated QA fixture.'],
        ]]);
    }
    // Check the actual engine enforces score bounds, rather than merely accepting DDL.
    $rejected = false;
    try {
        AssessmentScore::create(['assessment_id' => $assessment->id, 'criterion_id' => $a->id, 'score' => 6]);
    } catch (QueryException $e) {
        $rejected = true;
    }
    verify($rejected, 'MySQL accepted an invalid/duplicate score.');
    $scoreRow = $assessment->assessmentScores()->first();
    $rejected = false;
    try {
        $scoreRow->update(['score' => 6]);
    } catch (QueryException $e) {
        $rejected = true;
    }
    verify($rejected, 'MySQL did not enforce the score CHECK constraint.');
    $rejected = false;
    try {
        PairwiseComparison::create(['criterion_a_id' => $a->id, 'criterion_b_id' => $a->id, 'comparison_value' => 1, 'created_by' => $actor->id]);
    } catch (QueryException $e) {
        $rejected = true;
    }
    verify($rejected, 'MySQL accepted a self comparison.');
    $setting = SystemSetting::updateOrCreate(['key' => 'policy'], ['value' => ['institution_name' => 'QA Higher Education Institution', 'reassessment_months' => 12, 'thresholds' => ['critical' => 80, 'high' => 60, 'medium' => 40]]]);
    app(AuditService::class)->record('settings.qa_verified', $setting);
    $run = app(PrioritizationService::class)->run($actor, 'QA fixture — network planning review');
    $result = $run->prioritizationResults()->where('network_asset_id', NetworkAsset::where('asset_code', 'QA-'.$suffix.'-1')->first()->id)->firstOrFail();
    verify(abs($result->final_score - 90) < 1e-10, 'MySQL weighted score did not match 90.');
    verify($result->priority_level === 'critical', 'MySQL priority mismatch.');
    verify($run->decision_snapshot['institution_name'] === 'QA Higher Education Institution', 'MySQL JSON snapshot mismatch.');

    $session = app('session')->driver();
    $session->regenerateToken();
    $request = Request::create('http://localhost/dashboard');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $actor);
    $route = new Route('GET', 'dashboard', fn () => null);
    $route->name('dashboard');
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);
    URL::forceRootUrl('http://localhost');
    app('view')->share('errors', new ViewErrorBag);
    $draft = app(AssessmentService::class)->save($actor, ['network_asset_id' => $asset->id, 'assessment_date' => today()->toDateString(), 'status' => 'draft', 'scores' => [$a->id => ['score' => 3], $b->id => ['score' => 4]]]);
    $draft->assessmentScores()->where('criterion_id', $a->id)->update(['raw_value' => 72, 'raw_unit' => '%', 'remarks' => 'QA retained batch evidence']);
    $batchRequest = Request::create('http://localhost/assessments/batch', 'GET', ['search' => $asset->asset_code]);
    $batchRequest->setLaravelSession($session);
    $batchRequest->setUserResolver(fn () => $actor);
    $batchToken = app(BatchAssessmentController::class)->index($batchRequest)->getData()['token'];
    $beforeBatchCount = Assessment::count();
    $batchPost = Request::create('http://localhost/assessments/batch', 'POST', ['batch_token' => $batchToken, 'action' => 'draft', 'rows' => [$asset->id => ['selected' => 1, 'assessment_date' => today()->toDateString(), 'scores' => [$a->id => 3.25, $b->id => 4, $c->id => null]]]]);
    $batchPost->setLaravelSession($session);
    $batchPost->setUserResolver(fn () => $actor);
    app(BatchAssessmentController::class)->store($batchPost, app(AssessmentService::class));
    $retained = $draft->assessmentScores()->where('criterion_id', $a->id)->first();
    verify(Assessment::count() === $beforeBatchCount && abs($retained->score - 3.25) < 1e-10 && (float) $retained->raw_value === 72.0 && $retained->raw_unit === '%' && $retained->remarks === 'QA retained batch evidence', 'MySQL batch draft resumption or evidence preservation failed.');
    verify(! $session->has('batch_assessments.'.$batchToken), 'MySQL batch token was not consumed.');
    $session->forget(['status', 'batch_saved']);
    $customRole = Role::create(['code' => 'qa_reviewer_'.$suffix, 'name' => 'QA Reviewer', 'permissions' => ['view-assets', 'view-rankings']]);
    $screenUsers = [];
    foreach (['dashboard-management' => 'management', 'dashboard-evaluator' => 'evaluator', 'profile-unassigned' => null, 'dashboard-limited' => 'qa_overview_'.$suffix] as $screen => $roleCode) {
        $viewer = new User(['name' => 'QA '.($roleCode ?? 'Unassigned'), 'email' => $screen.'-'.$suffix.'@example.test', 'password' => bin2hex(random_bytes(20))]);
        if ($roleCode) {
            $viewer->role()->associate(Role::firstOrCreate(['code' => $roleCode], ['name' => $roleCode, 'permissions' => ['view-dashboard']]));
        }
        $viewer->is_active = true;
        $viewer->save();
        $screenUsers[$screen] = $viewer;
    }
    $views = [
        'dashboard' => ['dashboard', 'dashboard', fn ($r) => app(DashboardController::class)->__invoke()],
        'dashboard-management' => ['dashboard', 'dashboard', fn ($r) => app(DashboardController::class)->__invoke()],
        'dashboard-evaluator' => ['dashboard', 'dashboard', fn ($r) => app(DashboardController::class)->__invoke()],
        'dashboard-limited' => ['dashboard', 'dashboard', fn ($r) => app(DashboardController::class)->__invoke()],
        'profile-unassigned' => ['profile.edit', 'profile', fn ($r) => app(ProfileController::class)->edit($r)],
        'dashboard-success' => ['dashboard', 'dashboard', fn ($r) => app(DashboardController::class)->__invoke()],
        'ahp' => ['ahp.index', 'ahp', fn ($r) => app(AhpController::class)->index(app(AhpService::class))],
        'ahp-unanswered' => ['ahp.index', 'ahp', function ($r) {
            $data = app(AhpController::class)->index(app(AhpService::class))->getData();
            $data['pairs'] = array_map(fn ($p) => array_replace($p, ['value' => null]), $data['pairs']);
            $data['calculation'] = null;
            $data['problem'] = null;

            return view('ahp.index', $data);
        }],
        'weights' => ['ahp.weights', 'criteria-weights', fn ($r) => app(AhpController::class)->index(app(AhpService::class))],
        'assessment' => ['assessments.show', 'assessments/'.$assessment->id, fn ($r) => app(AssessmentController::class)->show($assessment)],
        'assessment-form' => ['assessments.edit', 'assessments/'.$draft->id.'/edit', fn ($r) => app(AssessmentController::class)->edit($draft)],
        'assessments' => ['assessments.index', 'assessments', fn ($r) => app(AssessmentController::class)->index($r)],
        'asset' => ['assets.show', 'assets/'.$asset->id, fn ($r) => app(NetworkAssetController::class)->show($asset)],
        'asset-form' => ['assets.edit', 'assets/'.$asset->id.'/edit', fn ($r) => app(NetworkAssetController::class)->edit($asset)],
        'asset-validation' => ['assets.edit', 'assets/'.$asset->id.'/edit', fn ($r) => app(NetworkAssetController::class)->edit($asset)],
        'assets' => ['assets.index', 'assets', fn ($r) => app(NetworkAssetController::class)->index($r)],
        'assets-empty' => ['assets.index', 'assets?search=definitely-no-matching-qa-asset', fn ($r) => app(NetworkAssetController::class)->index($r)],
        'rankings' => ['runs.show', 'runs/'.$run->id, fn ($r) => app(PrioritizationController::class)->show($r, $run)],
        'run-compare' => ['runs.compare', 'runs/'.$run->id.'/compare', fn ($r) => app(PrioritizationController::class)->compare($r, $run)],
        'assessment-create' => ['assessments.create', 'assessments/create', fn ($r) => app(AssessmentController::class)->create($r)],
        'assessment-batch' => ['assessments.batch', 'assessments/batch', fn ($r) => app(BatchAssessmentController::class)->index($r)],
        'runs' => ['runs.index', 'runs', fn ($r) => app(PrioritizationController::class)->index($r)],
        'run-create' => ['runs.create', 'runs/create', fn ($r) => app(PrioritizationController::class)->create($r)],
        'explanation' => ['runs.result', 'runs/'.$run->id.'/results/'.$result->id, fn ($r) => app(PrioritizationController::class)->result($run, $result)],
        'report' => ['reports.show', 'reports/rankings', fn ($r) => app(ReportController::class)->show($r, 'rankings', app(AhpService::class))],
        'reports' => ['reports.index', 'reports', fn ($r) => app(ReportController::class)->index()],
        'settings' => ['settings.edit', 'settings', fn ($r) => app(SettingsController::class)->edit(app(SettingsService::class))],
        'user-form' => ['users.edit', 'users/'.$actor->id.'/edit', fn ($r) => app(UserManagementController::class)->edit($actor)],
        'users' => ['users.index', 'users', fn ($r) => app(UserManagementController::class)->index($r)],
        'roles' => ['roles.index', 'roles', fn ($r) => app(RoleManagementController::class)->index()],
        'role-form' => ['roles.edit', 'roles/'.$customRole->id.'/edit', fn ($r) => app(RoleManagementController::class)->edit($customRole)],
        'audit' => ['audit.index', 'audit-logs', fn ($r) => app(AuditLogController::class)->index($r)],
        'administration' => ['administration.index', 'administration', fn ($r) => view('administration.index')],
        'profile' => ['profile.edit', 'profile', fn ($r) => app(ProfileController::class)->edit($r)],
        'login' => ['login', 'login', fn ($r) => view('auth.login')],
        'register' => ['register', 'register', fn ($r) => view('auth.register')],
        'forgot-password' => ['password.request', 'forgot-password', fn ($r) => view('auth.forgot-password')],
    ];
    $views['reset-password'] = ['password.reset', 'reset-password/qa-token', fn ($r) => view('auth.reset-password', ['request' => $r])];
    $views['confirm-password'] = ['password.confirm', 'confirm-password', fn ($r) => view('auth.confirm-password')];
    $views['verify-email'] = ['verification.notice', 'verify-email', fn ($r) => view('auth.verify-email')];
    $views['access-restricted'] = ['dashboard', 'dashboard', fn ($r) => view('errors.403')];
    $views['welcome'] = ['welcome', '', fn ($r) => view('welcome')];
    foreach (['locations', 'asset-types', 'criteria'] as $kind) {
        $views[$kind] = [$kind.'.index', $kind, fn ($r) => app(MasterDataController::class)->index($r)];
        $views[$kind.'-form'] = [$kind.'.create', $kind.'/create', fn ($r) => app(MasterDataController::class)->create($r)];
    }
    foreach (['inventory', 'assessments', 'critical', 'weights', 'consistency', 'history'] as $type) {
        $views['report-'.$type] = ['reports.show', 'reports/'.$type, fn ($r) => app(ReportController::class)->show($r, $type, app(AhpService::class))];
    }
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $css = file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));
    $js = file_get_contents(public_path('build/'.$manifest['resources/js/app.js']['file']));
    foreach ($views as $name => [$routeName,$uri,$render]) {
        $screenActor = $screenUsers[$name] ?? $actor;
        Auth::setUser($screenActor);
        $request = Request::create('http://localhost/'.$uri);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $screenActor);
        $route = new Route('GET', parse_url($uri, PHP_URL_PATH), fn () => null);
        $route->name($routeName);
        $route->bind($request);
        $route->setParameter('token', 'qa-token');
        $route->setParameter('run', $run);
        $route->setParameter('asset', $asset);
        $route->setParameter('assessment', $draft);
        $kind = explode('.', $routeName)[0];
        if (in_array($kind, ['locations', 'asset-types', 'criteria'])) {
            $route->setParameter('kind', $kind);
        }
        $request->setRouteResolver(fn () => $route);
        app()->instance('request', $request);
        $errors = new ViewErrorBag;
        if ($name === 'asset-validation') {
            $errors->put('default', new MessageBag(['asset_code' => ['This asset code is already in use. Choose a unique code.']]));
        }
        app('view')->share('errors', $errors);
        if ($name === 'dashboard-success') {
            $session->put('status', 'Prioritization completed successfully. Your results are preserved.');
        } else {
            $session->forget('status');
        }
        $view = $render($request);
        $html = $view->render();
        verify(! str_contains($html, 'Laravel documentation'), 'Unexpected starter content.');
        $html = preg_replace('#<link\b[^>]*>#i', '', $html);
        $html = preg_replace('#<script\b[^>]*src="[^"]*"[^>]*>\s*</script>#i', '', $html);
        $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        $script = str_replace('</script', '<\/script', $js);
        $html = str_replace('</body>', '<script type="module">'.$script.'</script><script>setTimeout(()=>{document.body.dataset.qaWidth=innerWidth;document.body.dataset.qaOverflow=document.documentElement.scrollWidth>innerWidth;},500)</script></body>', $html);
        file_put_contents(storage_path('app/qa/'.$name.'.html'), $html);
    }
    file_put_contents(storage_path('app/qa/screens.json'), json_encode(array_keys($views)));
    echo 'MySQL services, enforced constraints, snapshots and '.count($views).' rendered screens passed.'.PHP_EOL;
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    Auth::forgetGuards();
}
foreach ($counts as $table => $count) {
    verify(DB::table($table)->count() === $count, 'Fixture count changed after rollback: '.$table);
}
echo 'All MySQL fixture writes rolled back; original table counts preserved.'.PHP_EOL;
if (isset($failure)) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}
