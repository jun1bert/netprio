@php($singular = ['locations'=>'location','asset-types'=>'asset type','criteria'=>'criterion'][$kind])
<x-panel :title="$title">
    <x-slot name="actions">@can('create', $model)<a class="button" href="{{ route($kind.'.create') }}"><x-icon name="plus" />Add {{ $singular }}</a>@endcan</x-slot>
    @if($kind === 'criteria')<div class="notice">Criteria are configurable. Weights are calculated through AHP. Benefit: higher entered scores increase upgrade priority. Cost: lower entered scores increase upgrade priority. Changing criteria invalidates current weights; historical runs retain their snapshots.</div>@endif
    <form class="panel flex flex-wrap items-end gap-4" method="get">
        <x-field name="search" label="Search" :value="request('search')" />
        <x-field name="active" label="Status" type="select" :value="request('active')" :options="['' => 'All', 1 => 'Active', 0 => 'Inactive']" />
        <button class="button-secondary">Filter</button><a href="{{ route($kind.'.index') }}" class="text-link">Reset</a>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Name</th>@if($kind === 'criteria')<th>Direction</th><th>Weight</th>@elseif($kind === 'locations')<th>Building / floor</th>@endif<th>Status</th><th>Actions</th></tr></thead><tbody>
        @forelse($records as $record)<tr><td><strong>{{ $record->name }}</strong><p class="text-sm text-slate-500">{{ $record->description }}</p></td>
        @if($kind === 'criteria')<td>{{ ucfirst($record->direction) }}</td><td>{{ $record->weight === null ? 'Awaiting AHP' : number_format($record->weight * 100, 2).'%' }}</td>@elseif($kind === 'locations')<td>{{ $record->building }} {{ $record->floor }}</td>@endif
        <td><x-status-badge :status="$record->is_active ? 'active' : 'inactive'" /></td><td>@can('update', $record)<div class="table-actions"><a class="text-link" href="{{ route($kind.'.edit', $record->id) }}">Edit</a><form method="post" action="{{ route($kind.'.destroy', $record->id) }}" data-confirm-title="Delete {{ $record->name }}?" data-confirm-message="This permanently removes the record. Records used by assets or decisions must be deactivated to preserve their history." data-confirm-action="Delete record">@csrf @method('DELETE')<button class="destructive-link">Delete</button></form></div>@endcan</td></tr>
        @empty<tr><td colspan="5" class="empty-state"><x-empty-state title="No matching records" description="Add an institutional record to get started, or reset the filters to see existing records." :href="auth()->user()->can('create', $model) ? route($kind.'.create') : null" :action="'Add '.$singular" /></td></tr>@endforelse
    </tbody></table></div>{{ $records->links() }}
</x-panel>