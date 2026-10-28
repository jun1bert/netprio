<x-panel :title="($record->exists ? 'Edit ' : 'Add ').(['locations'=>'location','asset-types'=>'asset type','criteria'=>'criterion'][$kind])">
    <form data-unsaved data-state-field="is_active" data-state-original="{{ (int)$record->is_active }}" data-state-title="Deactivate this record?" data-state-message="The record will no longer be available for new assignments. Existing asset and decision history will remain available." class="panel space-y-5 max-w-3xl" method="post" action="{{ $record->exists ? route($kind.'.update', $record->id) : route($kind.'.store') }}">
        @csrf @if($record->exists) @method('PUT') @endif
        <x-field name="name" label="Name *" :value="$record->name" required maxlength="255" />
        @if($kind === 'locations')<div class="form-grid"><x-field name="building" label="Building" :value="$record->building" /><x-field name="floor" label="Floor" :value="$record->floor" /></div>@endif
        @if($kind === 'criteria')
            <x-field name="code" label="Unique code *" :value="$record->code" required maxlength="50" />
            <x-field name="direction" label="Scoring direction *" type="select" :value="$record->direction" :options="['benefit' => 'Benefit — higher score means higher upgrade priority', 'cost' => 'Cost — lower score means higher upgrade priority']" help="Choose the meaning of scores before assessment. Direction cannot change after scores have been recorded." />
            <x-field name="display_order" label="Display order *" type="number" :value="$record->display_order" min="0" max="10000" required />
        @endif
        <x-field name="description" label="Description / evaluator guidance" type="textarea" :value="$record->description" />
        @if($kind === 'criteria')
        @php($suggested=app(App\Services\ScoringRubricService::class)->for(new App\Models\Criterion(['code'=>$record->code,'direction'=>$record->direction ?? 'benefit'])))
        <details class="border border-slate-200 rounded-lg p-4" @if($record->rubric || old('rubric') || $errors->has('rubric') || $errors->has('rubric.*')) open @endif><summary class="text-sm font-semibold cursor-pointer">Define score descriptions (1–5)</summary><p class="field-help mt-3">Provide all five descriptions to define an institutional rubric, or leave all five blank to use the suggested/general scale. Describe observable differences between scores. These descriptions guide evaluators; they do not automatically convert raw measurements.</p>@error('rubric')<p class="field-error mt-3" role="alert">{{ $message }}</p>@enderror<div class="space-y-4 mt-4">@foreach(range(1,5) as $level)<x-field :name="'rubric['.$level.']'" :label="'Score '.$level.' / 5'" type="textarea" :value="$record->rubric[$level] ?? ''" :help="'Default: '.$suggested['levels'][$level]" maxlength="1000" />@endforeach</div><p class="field-help mt-4">Changing guidance affects future/draft assessments. Completed assessments keep any guidance stored with their scores. Review the wording if you change the criterion code or scoring direction.</p></details>
        @endif
        <x-field name="is_active" label="Status" type="select" :value="(int)$record->is_active" :options="[1 => 'Active', 0 => 'Inactive']" />
        <div class="form-actions"><a class="button-secondary" href="{{ route($kind.'.index') }}">Cancel</a><button class="button">Save record</button></div>
    </form>
</x-panel>