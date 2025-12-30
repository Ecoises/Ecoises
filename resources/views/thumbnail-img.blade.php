{{-- resources/views/thumbnail-img.blade.php --}}
@php
    $state = $getState(); // Obtiene el valor del campo (ruta relativa)
@endphp

@if($state)
    <div style="display: flex; justify-content: center; align-items: center;">
        <img 
            src="{{ asset('storage/' . $state) }}" 
            alt="Miniatura del curso"
            style="
                max-height: 250px;
                width: auto;
                border-radius: 8px;
                object-fit: cover;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
                transition: transform 0.2s ease;
            "
           
        >
    </div>
@else
    <div style="display: flex; justify-content: center; align-items: center; padding: 16px;">
        <span style="color: #6b7280; font-size: 14px;">Sin miniatura</span>
    </div>
@endif