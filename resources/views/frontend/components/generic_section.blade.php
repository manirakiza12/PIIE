{{-- Generic Section Renderer.

     THE ORANGE "Content Section" BADGE IS GONE.
     It identified the CMS rather than the institution, appeared identically on every
     section of every page, and told a visitor nothing. The section title below is
     the heading, and that is all a heading needs.

     NOTE: the public site no longer renders this component at all - `website_page`
     lays sections out by their key using the shared blocks. It is retained because
     `WebsiteRenderingHelper::renderSection()` still resolves section keys to it, so
     removing the file would break that helper for any caller. --}}
<section class="piie-section" id="{{ $section->section_key }}">
    <div class="piie-wrap">
        <!-- Section Title -->
        <div class="piie-head-center">
            <h2>{{ $section->title ?: ucwords(str_replace('_', ' ', $section->section_key)) }}</h2>
            @if(!empty($section->subtitle))
                <p class="piie-lede">{{ $section->subtitle }}</p>
            @endif
        </div>

        <!-- Section Content -->
        @if(!empty($section->content))
            <div class="row mb-4">
                <div class="col-lg-10" style="grid-column: span 10; margin: 0 auto;">
                    <div class="content-box">
                        {!! nl2br(e($section->content)) !!}
                    </div>
                </div>
            </div>
        @endif

        <!-- Section Image -->
        @if(!empty($section->image))
            <div class="row mb-4">
                <div class="col-lg-8" style="grid-column: span 8; margin: 0 auto; text-align: center;">
                    <img src="{{ asset('assets/uploads/website/'.$section->image) }}" 
                         alt="{{ $section->title }}" 
                         style="max-width:100%; height:auto; border-radius:var(--radius-lg); box-shadow:var(--shadow-lg); transition:transform 0.3s ease;"
                         onmouseover="this.style.transform='scale(1.02)'"
                         onmouseout="this.style.transform='scale(1)'">
                </div>
            </div>
        @endif

        <!-- Section Items Grid -->
        @if($items && $items->count() > 0)
            <div class="row g-4" style="margin-top: 40px;">
                @foreach($items as $item)
                    @if($item->status == 1)
                        <div class="col-lg-4" style="grid-column: span 4;">
                            <div class="card-item">
                                <!-- Item Image -->
                                @if(!empty($item->image))
                                    <img src="{{ asset('assets/uploads/website/'.$item->image) }}" 
                                         alt="{{ $item->title }}" 
                                         class="card-image">
                                @endif
                                
                                <!-- Item Badge -->
                                @if(!empty($item->badge))
                                    <span class="card-badge">
                                        <i class="fas fa-star" style="margin-right: 4px;"></i>
                                        {{ $item->badge }}
                                    </span>
                                @endif

                                <!-- Item Title -->
                                <h5 class="card-title">{{ $item->title }}</h5>

                                <!-- Item Subtitle -->
                                @if(!empty($item->subtitle))
                                    <p class="card-subtitle">
                                        <i class="fas fa-check-circle" style="margin-right: 6px;"></i>
                                        {{ $item->subtitle }}
                                    </p>
                                @endif

                                <!-- Item Description -->
                                @if(!empty($item->description))
                                    <p class="card-description">{{ $item->description }}</p>
                                @endif

                                <!-- Item Content Preview -->
                                @if(!empty($item->content))
                                    <div style="font-size:13px; color:var(--text-secondary); margin-bottom:12px; line-height:1.7;">
                                        {!! nl2br(e($item->content)) !!}
                                    </div>
                                @endif

                                <!-- Item Button -->
                                @if(!empty($item->link) || !empty($item->button_text))
                                    <a href="{{ $item->link ?: '#' }}" target="_blank" class="card-button">
                                        {{ $item->button_text ?: 'Learn More' }}
                                        <i class="fas fa-arrow-right" style="margin-left: 6px;"></i>
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @elseif(empty($section->content) && empty($section->image))
            <div style="background:var(--light-bg); border:2px dashed var(--border-color); border-radius:var(--radius-lg); padding:40px; text-align:center; color:var(--text-secondary);">
                <i class="fas fa-inbox" style="font-size:32px; margin-bottom:12px; opacity:0.5;"></i>
                <p style="margin:0; font-size:15px;">No items added for this section yet.</p>
            </div>
        @endif
    </div>
</section>
