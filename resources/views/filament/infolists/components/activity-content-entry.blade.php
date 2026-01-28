@php
    $state = $getState();
    $activityType = $getActivityType();
    $activityTitle = $getActivityTitle();
    $activityExplanation = $getActivityExplanation();
@endphp

<x-dynamic-component
    :component="$getEntryWrapperView()"
    :entry="$entry"
>
    <div class="space-y-3">
        {{-- Badge del tipo de actividad --}}
        <div>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300">
                @switch($activityType)
                    @case('quiz_multiple')
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        Selección múltiple
                        @break
                    @case('quiz_true_false')
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Verdadero/Falso
                        @break
                    @case('matching')
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                        </svg>
                        Emparejar
                        @break
                    @case('drag_drop')
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122" />
                        </svg>
                        Arrastrar y soltar
                        @break
                    @default
                        {{ $activityType }}
                @endswitch
            </span>
        </div>

        {{-- Pregunta/Enunciado --}}
        @if($activityTitle)
            <div class="p-4 rounded-lg bg-gray-50 border-l-4 border-primary-500 dark:bg-gray-800">
                <p class="font-semibold text-gray-900 dark:text-gray-100">
                    {{ $activityTitle }}
                </p>
            </div>
        @endif

        {{-- Contenido según el tipo --}}
        @if($activityType === 'quiz_multiple')
            {{-- Selección múltiple --}}
            @if(is_array($state) && isset($state['options']))
                <div class="space-y-2">
                    @foreach($state['options'] as $option)
                        <div class="flex items-start gap-3 p-3 rounded-lg border transition-colors
                            {{ ($option['is_correct'] ?? false) 
                                ? 'bg-success-50 border-success-300 dark:bg-success-900/20 dark:border-success-700' 
                                : 'bg-white border-gray-200 dark:bg-gray-900 dark:border-gray-700' }}">
                            
                            <div class="flex-shrink-0 mt-0.5">
                                @if($option['is_correct'] ?? false)
                                    <div class="w-6 h-6 rounded-full bg-success-500 flex items-center justify-center">
                                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </div>
                                @else
                                    <div class="w-6 h-6 rounded-full bg-gray-200 dark:bg-gray-700"></div>
                                @endif
                            </div>
                            
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100">
                                    <span class="text-primary-600 dark:text-primary-400 font-bold">{{ chr(65 + $loop->index) }}.</span>
                                    {{ $option['text'] ?? 'Sin texto' }}
                                </p>
                                
                                @if(!empty($option['feedback']))
                                    <div class="mt-2 flex items-start gap-2 p-2 rounded bg-info-50 dark:bg-info-900/20">
                                        <svg class="w-4 h-4 text-info-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                        </svg>
                                        <p class="text-sm text-info-900 dark:text-info-100">
                                            {{ $option['feedback'] }}
                                        </p>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500 italic">Sin opciones definidas</p>
            @endif

        @elseif($activityType === 'quiz_true_false')
            @php
                $correctAnswer = null;
                $explanation = null;
                $feedbackCorrect = null;
                $feedbackIncorrect = null;
                
                if (is_array($state)) {
                    // Nueva estructura: {"correct_answer": "true/false", "feedback_correct": "...", "feedback_incorrect": "..."}
                    if (isset($state['correct_answer'])) {
                        $correctAnswer = $state['correct_answer'] === 'true' || $state['correct_answer'] === true;
                        $feedbackCorrect = $state['feedback_correct'] ?? null;
                        $feedbackIncorrect = $state['feedback_incorrect'] ?? null;
                    } 
                    // Estructura anterior: {"is_true": "true/false", "true_false_feedback": "texto"}
                    elseif (isset($state['is_true'])) {
                        $correctAnswer = $state['is_true'] === 'true' || $state['is_true'] === true;
                        $explanation = $state['true_false_feedback'] ?? null;
                    }
                    // Estructura alternativa antigua: [true/false, "explicación"]
                    elseif (isset($state[0])) {
                        $correctAnswer = $state[0] === 'true' || $state[0] === true;
                        $explanation = $state[1] ?? null;
                    }
                }
            @endphp

            @if($correctAnswer !== null)
                <div class="grid grid-cols-2 gap-4">
                    {{-- Opción Verdadero --}}
                    <div class="p-4 rounded-lg border text-center transition-all
                        {{ $correctAnswer === true
                            ? 'bg-success-50 border-success-300 ring-2 ring-success-200 dark:bg-success-900/20 dark:border-success-700' 
                            : 'bg-white border-gray-200 dark:bg-gray-900 dark:border-gray-700' }}">
                        
                        <div class="w-12 h-12 mx-auto mb-3 rounded-full flex items-center justify-center
                            {{ $correctAnswer === true
                                ? 'bg-success-500' 
                                : 'bg-gray-200 dark:bg-gray-700' }}">
                            <svg class="w-6 h-6 {{ $correctAnswer === true ? 'text-white' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        
                        <p class="font-bold text-lg text-gray-900 dark:text-gray-100">Verdadero</p>
                        
                        @if($correctAnswer === true)
                            <p class="text-xs text-success-600 dark:text-success-400 mt-2 font-semibold">✓ Respuesta correcta</p>
                        @endif
                    </div>
                    
                    {{-- Opción Falso --}}
                    <div class="p-4 rounded-lg border text-center transition-all
                        {{ $correctAnswer === false
                            ? 'bg-success-50 border-success-300 ring-2 ring-success-200 dark:bg-success-900/20 dark:border-success-700' 
                            : 'bg-white border-gray-200 dark:bg-gray-900 dark:border-gray-700' }}">
                        
                        <div class="w-12 h-12 mx-auto mb-3 rounded-full flex items-center justify-center
                            {{ $correctAnswer === false
                                ? 'bg-success-500' 
                                : 'bg-gray-200 dark:bg-gray-700' }}">
                            <svg class="w-6 h-6 {{ $correctAnswer === false ? 'text-white' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </div>
                        
                        <p class="font-bold text-lg text-gray-900 dark:text-gray-100">Falso</p>
                        
                        @if($correctAnswer === false)
                            <p class="text-xs text-success-600 dark:text-success-400 mt-2 font-semibold">✓ Respuesta correcta</p>
                        @endif
                    </div>
                </div>
                
                {{-- Explicación / Feedback Correcto --}}
                @if($feedbackCorrect && $correctAnswer !== null)
                     <div class="mt-3 p-4 bg-success-50 border-l-4 border-success-500 rounded-r-lg dark:bg-success-900/20 dark:border-success-700">
                        <div class="flex items-start gap-2">
                            <svg class="w-5 h-5 text-success-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <div>
                                <p class="font-semibold text-success-900 dark:text-success-100 mb-1">Feedback si aciertan</p>
                                <p class="text-sm text-success-800 dark:text-success-200">{{ $feedbackCorrect }}</p>
                            </div>
                        </div>
                    </div>
                @endif

                 {{-- Explicación / Feedback Incorrecto --}}
                 @if($feedbackIncorrect && $correctAnswer !== null)
                     <div class="mt-3 p-4 bg-danger-50 border-l-4 border-danger-500 rounded-r-lg dark:bg-danger-900/20 dark:border-danger-700">
                        <div class="flex items-start gap-2">
                            <svg class="w-5 h-5 text-danger-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <div>
                                <p class="font-semibold text-danger-900 dark:text-danger-100 mb-1">Feedback si fallan</p>
                                <p class="text-sm text-danger-800 dark:text-danger-200">{{ $feedbackIncorrect }}</p>
                            </div>
                        </div>
                    </div>
                @endif
                
                {{-- Explicación Antigua (Fallback) --}}
                @if($explanation)
                    <div class="mt-3 p-4 bg-info-50 border-l-4 border-info-500 rounded-r-lg dark:bg-info-900/20 dark:border-info-700">
                        <div class="flex items-start gap-2">
                            <svg class="w-5 h-5 text-info-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                            </svg>
                            <div>
                                <p class="font-semibold text-info-900 dark:text-info-100 mb-1">Explicación</p>
                                <p class="text-sm text-info-800 dark:text-info-200">{{ $explanation }}</p>
                            </div>
                        </div>
                    </div>
                @endif
            @else
                <p class="text-sm text-gray-500 italic">Sin respuesta definida</p>
            @endif

        @elseif($activityType === 'matching')
            {{-- Emparejar --}}
            @php
                $pairs = [];
                if (is_array($state)) {
                    // Estructura: {"pairs": [...]}
                    if (isset($state['pairs']) && is_array($state['pairs'])) {
                        $pairs = $state['pairs'];
                    }
                    // Estructura directa: [{"term": "...", "match": "..."}, ...]
                    elseif (isset($state[0]) && is_array($state[0]) && isset($state[0]['term'])) {
                        $pairs = $state;
                    }
                }
            @endphp

            @if(count($pairs) > 0)
                <div class="space-y-3">
                    @foreach($pairs as $pair)
                        <div class="group">
                            <div class="flex items-center gap-4 p-4 rounded-lg bg-white border border-gray-200 dark:bg-gray-900 dark:border-gray-700 hover:border-primary-300 dark:hover:border-primary-700 transition-colors">
                                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center">
                                    <span class="text-sm font-bold text-primary-700 dark:text-primary-300">{{ $loop->iteration }}</span>
                                </div>
                                
                                <div class="flex-1 font-medium text-gray-900 dark:text-gray-100">
                                    {{ $pair['term'] ?? 'Sin término' }}
                                </div>
                                
                                <div class="flex-shrink-0">
                                    <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                    </svg>
                                </div>
                                
                                <div class="flex-1 text-right font-medium text-primary-600 dark:text-primary-400">
                                    {{ $pair['match'] ?? 'Sin coincidencia' }}
                                </div>
                            </div>
                            
                            @if(!empty($pair['feedback']))
                                <div class="ml-12 mt-2 p-2 rounded bg-gray-50 dark:bg-gray-800 border-l-2 border-gray-300 dark:border-gray-600">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        💡 {{ $pair['feedback'] }}
                                    </p>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500 italic">Sin pares definidos</p>
            @endif

       @elseif($activityType === 'drag_drop')
    {{-- Arrastrar y soltar --}}
    @php
        $items = [];
        if (is_array($state)) {
            // Estructura KeyValue: {"items": {"key": "value"}}
            if (isset($state['items']) && is_array($state['items'])) {
                $items = $state['items'];
            }
            // Fallback: estructura directa
            elseif (!isset($state['items'])) {
                $items = $state;
            }
        }
    @endphp

    @if(count($items) > 0)
        <div class="space-y-2">
            @foreach($items as $element => $category)
                <div class="flex items-center gap-3 p-3 rounded-lg bg-white border border-gray-200 dark:bg-gray-900 dark:border-gray-700 hover:border-primary-300 dark:hover:border-primary-700 transition-colors">
                    <div class="flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full bg-primary-500 text-white font-bold shadow-sm">
                        {{ $loop->iteration }}
                    </div>
                    
                    <div class="flex-1 font-medium text-gray-900 dark:text-gray-100">
                        {{ $element }}
                    </div>
                    
                    <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                    
                    <div class="flex-shrink-0 px-3 py-1 rounded-full bg-primary-100 dark:bg-primary-900 text-primary-700 dark:text-primary-300 text-sm font-semibold">
                        {{ $category }}
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-sm text-gray-500 italic">Sin elementos definidos</p>
    @endif
        @else
            {{-- Formato genérico --}}
            <div class="p-4 rounded-lg bg-gray-50 border border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                <p class="text-xs text-gray-500 mb-2">Formato no reconocido:</p>
                <pre class="text-xs text-gray-600 dark:text-gray-400 overflow-x-auto">{{ json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        @endif

        {{-- Feedback general --}}
        @if($activityExplanation)
            <div class="p-4 bg-warning-50 border-l-4 border-warning-500 rounded-r-lg dark:bg-warning-900/20 dark:border-warning-700">
                <div class="flex items-start gap-2">
                    <svg class="w-5 h-5 text-warning-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                    </svg>
                    <div>
                        <p class="font-semibold text-warning-900 dark:text-warning-100 mb-1">Feedback General</p>
                        <p class="text-sm text-warning-800 dark:text-warning-200">{{ $activityExplanation }}</p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-dynamic-component>