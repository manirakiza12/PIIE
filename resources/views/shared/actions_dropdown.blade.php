{{-- Shared Actions Dropdown Component --}}
{{-- Usage: @include('shared.actions_dropdown', ['actions' => [...]]) --}}
{{-- Each action: ['label' => '...', 'url' => '...', 'method' => 'GET'|'POST'|'DELETE', 'variant' => 'primary'|'danger'|'secondary', 'confirm' => true|false, 'disabled' => true|false, 'permission' => true|false, 'modal' => 'modalId', 'onclick' => '...'] --}}

@php
    $actions = $actions ?? [];
    $visibleActions = array_filter($actions, fn($a) => ($a['permission'] ?? true) === true);
@endphp

@if(count($visibleActions) > 0)
<div class="adminTable-action">
    <button type="button" class="eBtn eBtn-black dropdown-toggle table-action-btn-2"
            data-bs-toggle="dropdown" aria-expanded="false"
            aria-label="{{ get_phrase('Actions') }}">
        {{ get_phrase('Actions') }}
    </button>
    <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action">
        @foreach($visibleActions as $action)
            <li>
                @if(($action['disabled'] ?? false) === true)
                    <span class="dropdown-item-text small text-muted" aria-disabled="true">
                        {{ $action['label'] }}
                    </span>
                @elseif(($action['variant'] ?? '') === 'danger' || ($action['method'] ?? 'GET') === 'DELETE')
                    @if(isset($action['modal']))
                        <button class="dropdown-item text-danger" type="button"
                                data-bs-toggle="modal" data-bs-target="#{{ $action['modal'] }}">
                            {{ $action['label'] }}
                        </button>
                    @elseif(($action['confirm'] ?? false) === true)
                        <a class="dropdown-item text-danger" href="javascript:;"
                           onclick="confirmModal('{{ $action['url'] }}', 'undefined');">
                            {{ $action['label'] }}
                        </a>
                    @else
                        <a class="dropdown-item text-danger" href="{{ $action['url'] }}">
                            {{ $action['label'] }}
                        </a>
                    @endif
                @elseif(($action['method'] ?? 'GET') === 'POST' && ($action['confirm'] ?? false) === true)
                    <a class="dropdown-item" href="javascript:;"
                       onclick="confirmModal('{{ $action['url'] }}', 'undefined');">
                        {{ $action['label'] }}
                    </a>
                @elseif(isset($action['onclick']))
                    <a class="dropdown-item" href="javascript:;" onclick="{{ $action['onclick'] }}">
                        {{ $action['label'] }}
                    </a>
                @elseif(isset($action['modal']))
                    <a class="dropdown-item" href="javascript:;" data-bs-toggle="modal" data-bs-target="#{{ $action['modal'] }}">
                        {{ $action['label'] }}
                    </a>
                @else
                    <a class="dropdown-item" href="{{ $action['url'] }}" {{ $action['target'] ?? '' }}>
                        {{ $action['label'] }}
                    </a>
                @endif
            </li>
        @endforeach
    </ul>
</div>
@endif