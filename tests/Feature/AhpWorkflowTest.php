<?php

namespace Tests\Feature;

use App\Models\Criterion;
use App\Services\AhpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsNetpriorRecords;
use Tests\TestCase;

class AhpWorkflowTest extends TestCase
{
    use BuildsNetpriorRecords, RefreshDatabase;

    public function test_pairwise_validation_calculation_and_consistency_guard(): void
    {
        $this->actingAs($this->institutionUser());
        $ids = collect(['A', 'B', 'C'])->map(fn ($code) => Criterion::create(['code' => $code, 'name' => $code, 'direction' => 'benefit'])->id)->all();
        [$a,$b,$c] = $ids;
        $this->get(route('ahp.index'))->assertOk()->assertSee('Complete every active criterion pair');
        $this->post(route('ahp.store'), ['pairs' => [$a.'_'.$b => 2.5]])->assertSessionHasErrors();
        $this->assertDatabaseCount('pairwise_comparisons', 0);
        $this->post(route('ahp.store'), ['pairs' => [$a.'_'.$b => 2, $a.'_'.$c => 4, $b.'_'.$c => 2]])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(4 / 7, Criterion::find($a)->weight, 1e-11);
        $this->get(route('ahp.index'))->assertOk()->assertSee('Acceptable consistency');
        $this->post(route('ahp.store'), ['pairs' => [$a.'_'.$b => 9, $a.'_'.$c => 1 / 9, $b.'_'.$c => 9]])->assertRedirect(route('ahp.index'))->assertSessionHasNoErrors();
        $this->assertSame(3, Criterion::whereNull('weight')->count());
        $this->get(route('ahp.index'))->assertOk()->assertSee('Inconsistent judgments');
        $this->assertFalse(app(AhpService::class)->analyze()['consistent']);
        $this->actingAs($this->institutionUser('management'))->post(route('ahp.store'), ['pairs' => []])->assertForbidden();
    }
}
