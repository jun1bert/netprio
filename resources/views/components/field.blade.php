@props(['name','label','value'=>'','type'=>'text','options'=>[],'help'=>null])
@php
$key = trim(str_replace(['[',']'],['.',''],$name),'.');
$id = 'field-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$key);
$current = old($key,$value);
$invalid = $errors->has($key);
$described = trim(($help ? $id.'-help ' : '').($invalid ? $id.'-error' : ''));
@endphp
<div class="field-group">
<label for="{{ $id }}" class="field-label">{{ rtrim($label, ' *') }}@if($attributes->has('required') || str_ends_with(trim($label),'*'))<span aria-hidden="true" class="text-blue-700 ml-1">*</span><span class="sr-only"> (required)</span>@endif</label>
@if($type==='select')<select id="{{ $id }}" name="{{ $name }}" @if($described) aria-describedby="{{ $described }}" @endif aria-invalid="{{ $invalid ? 'true' : 'false' }}" {{ $attributes->class(['form-input','input-invalid'=>$invalid]) }}>@foreach($options as $option=>$text)<option value="{{ $option }}" @selected((string)$current===(string)$option)>{{ $text }}</option>@endforeach</select>
@elseif($type==='textarea')<textarea id="{{ $id }}" name="{{ $name }}" rows="3" @if($described) aria-describedby="{{ $described }}" @endif aria-invalid="{{ $invalid ? 'true' : 'false' }}" {{ $attributes->class(['form-input','input-invalid'=>$invalid]) }}>{{ $current }}</textarea>
@else<input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $current }}" @if($described) aria-describedby="{{ $described }}" @endif aria-invalid="{{ $invalid ? 'true' : 'false' }}" {{ $attributes->class(['form-input','input-invalid'=>$invalid]) }}>@endif
@if($help)<p id="{{ $id }}-help" class="field-help">{{ $help }}</p>@endif
@error($key)<p id="{{ $id }}-error" class="field-error">{{ $message }}</p>@enderror
</div>