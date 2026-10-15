@if ($imageUrl)
    <img src="{{ $imageUrl }}" alt="{{ $imageLabel }}" class="my-3 mx-auto d-block" style="max-width:100%;width:auto;height:90px;object-fit:contain">
@else
    <p class="text-muted my-4">{{ get_phrase('No image uploaded') }}</p>
@endif
