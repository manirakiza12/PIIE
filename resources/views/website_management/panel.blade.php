<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Website Management') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Settings') }}</a></li>
                        <li><a href="#">{{ get_phrase('Website Management') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="col-12">
    <div class="eSection-wrap">
        <div class="eMain">
            <p class="column-title">{{ get_phrase('Core Website Settings') }}</p>
            <form method="POST" class="ajaxForm" action="{{ route($routePrefix.'.settings.upsert') }}">
                @csrf
                @php
                    $defaultSettings = [
                        'institution_name' => 'Twinehs Divine Integrated Institute of Business and Technology (TDIIBT)',
                        'tagline' => 'Quality Education | Practical Skills | Professional Excellence',
                        'motto' => 'Learning for Impact',
                        'contact_address' => 'P.O. Box 202386 Kampala GPO',
                        'contact_phone_1' => '+256 707390607',
                        'contact_phone_2' => '+256 788099193',
                        'contact_email' => 'info@tdiibt.ac.ug',
                        'contact_website' => 'http://www.tdiibt.ac.ug',
                        'director_name' => 'Twinamatsiko Naboth PhD(c)',
                        'institute_secretary_name' => 'Mr. Bendaki Evans',
                        'footer_copyright' => '© Twinehs Divine Integrated Institute of Business and Technology (TDIIBT). All Rights Reserved.',
                    ];
                @endphp
                <div class="row">
                    @foreach($defaultSettings as $key => $defaultValue)
                        <div class="col-md-6">
                            <div class="fpb-7">
                                <label class="eForm-label">{{ ucwords(str_replace('_', ' ', $key)) }}</label>
                                <input type="hidden" name="settings[{{ $loop->index }}][key]" value="{{ $key }}">
                                <input type="hidden" name="settings[{{ $loop->index }}][is_json]" value="0">
                                <input type="hidden" name="settings[{{ $loop->index }}][status]" value="1">
                                <input type="text" class="form-control eForm-control" name="settings[{{ $loop->index }}][value]" value="{{ $settings[$key]->value ?? $defaultValue }}">
                            </div>
                        </div>
                    @endforeach
                </div>
                <button type="submit" class="btn-form">{{ get_phrase('Update Settings') }}</button>
            </form>
        </div>
    </div>
</div>

<div class="col-12 mt-4">
    <div class="eSection-wrap">
        <div class="eMain">
            <p class="column-title">{{ get_phrase('Create Website Section') }}</p>
            <form method="POST" enctype="multipart/form-data" class="ajaxForm" action="{{ route($routePrefix.'.section.store') }}">
                @csrf
                <div class="row">
                    <div class="col-md-3 fpb-7">
                        <label class="eForm-label">Page Key</label>
                        <input type="text" name="page_key" class="form-control eForm-control" value="home" required>
                    </div>
                    <div class="col-md-3 fpb-7">
                        <label class="eForm-label">Section Key</label>
                        <select name="section_key" class="form-control eForm-control" required>
                            @foreach($modules as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 fpb-7">
                        <label class="eForm-label">Title</label>
                        <input type="text" name="title" class="form-control eForm-control">
                    </div>
                    <div class="col-md-3 fpb-7">
                        <label class="eForm-label">Subtitle</label>
                        <input type="text" name="subtitle" class="form-control eForm-control">
                    </div>
                    <div class="col-md-6 fpb-7">
                        <label class="eForm-label">Content</label>
                        <textarea name="content" class="form-control eForm-control" rows="3"></textarea>
                    </div>
                    <div class="col-md-3 fpb-7">
                        <label class="eForm-label">Image</label>
                        <input type="file" name="image" class="form-control eForm-control-file">
                    </div>
                    <div class="col-md-1 fpb-7">
                        <label class="eForm-label">Order</label>
                        <input type="number" name="sort_order" class="form-control eForm-control" value="0">
                    </div>
                    <div class="col-md-2 fpb-7">
                        <label class="eForm-label">Status</label>
                        <select name="status" class="form-control eForm-control">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-form">{{ get_phrase('Create Section') }}</button>
            </form>
        </div>
    </div>
</div>

<div class="col-12 mt-4">
    <div class="eSection-wrap">
        <div class="eMain">
            <p class="column-title">{{ get_phrase('Website Sections') }}</p>
            <div class="table-responsive">
                <table class="table eTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Section Key</th>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Order</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sections as $section)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $section->section_key }}</td>
                                <td>{{ $section->title }}</td>
                                <td>{{ $section->status ? 'Active' : 'Inactive' }}</td>
                                <td>{{ $section->sort_order }}</td>
                                <td class="d-flex gap-2">
                                    <a href="javascript:;" class="eBtn eBtn-black" onclick="document.getElementById('edit-section-{{ $section->id }}').classList.toggle('d-none')">Edit</a>
                                    <a href="{{ route($routePrefix.'.section.delete', $section->id) }}" class="eBtn eBtn-red" onclick="return confirm('Delete this section?')">Delete</a>
                                </td>
                            </tr>
                            <tr id="edit-section-{{ $section->id }}" class="d-none">
                                <td colspan="6">
                                    <form method="POST" enctype="multipart/form-data" action="{{ route($routePrefix.'.section.update', $section->id) }}">
                                        @csrf
                                        <div class="row">
                                            <div class="col-md-2 fpb-7"><input type="text" class="form-control eForm-control" name="page_key" value="{{ $section->page_key }}"></div>
                                            <div class="col-md-2 fpb-7"><input type="text" class="form-control eForm-control" name="section_key" value="{{ $section->section_key }}"></div>
                                            <div class="col-md-2 fpb-7"><input type="text" class="form-control eForm-control" name="title" value="{{ $section->title }}"></div>
                                            <div class="col-md-2 fpb-7"><input type="text" class="form-control eForm-control" name="subtitle" value="{{ $section->subtitle }}"></div>
                                            <div class="col-md-2 fpb-7"><input type="number" class="form-control eForm-control" name="sort_order" value="{{ $section->sort_order }}"></div>
                                            <div class="col-md-2 fpb-7">
                                                <select name="status" class="form-control eForm-control">
                                                    <option value="1" {{ $section->status ? 'selected' : '' }}>Active</option>
                                                    <option value="0" {{ !$section->status ? 'selected' : '' }}>Inactive</option>
                                                </select>
                                            </div>
                                            <div class="col-md-8 fpb-7"><textarea class="form-control eForm-control" name="content" rows="2">{{ $section->content }}</textarea></div>
                                            <div class="col-md-2 fpb-7"><input type="file" name="image" class="form-control eForm-control-file"></div>
                                            <div class="col-md-2 fpb-7"><button type="submit" class="btn-form">Update</button></div>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="col-12 mt-4">
    <div class="eSection-wrap">
        <div class="eMain">
            <p class="column-title">{{ get_phrase('Create Website Item') }}</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route($routePrefix.'.item.store') }}">
                @csrf
                <div class="row">
                    <div class="col-md-2 fpb-7">
                        <label class="eForm-label">Section Key</label>
                        <select name="section_key" class="form-control eForm-control">
                            @foreach($modules as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 fpb-7"><label class="eForm-label">Item Type</label><input type="text" name="item_type" class="form-control eForm-control" value="general"></div>
                    <div class="col-md-2 fpb-7"><label class="eForm-label">Title</label><input type="text" name="title" class="form-control eForm-control"></div>
                    <div class="col-md-2 fpb-7"><label class="eForm-label">Subtitle</label><input type="text" name="subtitle" class="form-control eForm-control"></div>
                    <div class="col-md-2 fpb-7"><label class="eForm-label">Link</label><input type="text" name="link" class="form-control eForm-control"></div>
                    <div class="col-md-1 fpb-7"><label class="eForm-label">Order</label><input type="number" name="sort_order" class="form-control eForm-control" value="0"></div>
                    <div class="col-md-1 fpb-7"><label class="eForm-label">Status</label><select name="status" class="form-control eForm-control"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                    <div class="col-md-4 fpb-7"><label class="eForm-label">Description</label><textarea name="description" class="form-control eForm-control" rows="2"></textarea></div>
                    <div class="col-md-4 fpb-7"><label class="eForm-label">Content</label><textarea name="content" class="form-control eForm-control" rows="2"></textarea></div>
                    @include('website_management.partials.item_image_field', [
                        'idSuffix'     => 'new',
                        // The section is typed into the field beside this one, so the
                        // hint follows that field via JS rather than being asserted here.
                        'isProgramme'  => false,
                        'currentImage' => null,
                    ])
                    <div class="col-md-2 fpb-7">
                        {{-- Featured flag. Stored in the existing meta_json column as
                             {"featured": true}, so no migration is involved. Programme
                             items marked here become eligible for the homepage
                             Featured Programmes block automatically, with no code change
                             when a new one is published. --}}
                        <label class="eForm-label" for="item-featured">Featured</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="item-featured" name="featured">
                            <label class="form-check-label" for="item-featured">Show on homepage</label>
                        </div>
                    </div>
                    <div class="col-md-2 fpb-7"><button type="submit" class="btn-form">Create Item</button></div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="col-12 mt-4">
    <div class="eSection-wrap">
        <div class="eMain">
            <p class="column-title">{{ get_phrase('Website Items') }}</p>
            <div class="table-responsive">
                <table class="table eTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Section</th>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Order</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $item)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $item->section_key }}</td>
                                <td>{{ $item->title }}</td>
                                <td>{{ $item->status ? 'Active' : 'Inactive' }}</td>
                                <td>{{ $item->sort_order }}</td>
                                <td class="d-flex gap-2">
                                    <a href="javascript:;" class="eBtn eBtn-black" onclick="document.getElementById('edit-item-{{ $item->id }}').classList.toggle('d-none')">Edit</a>
                                    <a href="{{ route($routePrefix.'.item.delete', $item->id) }}" class="eBtn eBtn-red" onclick="return confirm('Delete this item?')">Delete</a>
                                </td>
                            </tr>
                            <tr id="edit-item-{{ $item->id }}" class="d-none">
                                <td colspan="6">
                                    <form method="POST" enctype="multipart/form-data" action="{{ route($routePrefix.'.item.update', $item->id) }}">
                                        @csrf
                                        <div class="row">
                                            <div class="col-md-2 fpb-7"><input type="text" class="form-control eForm-control" name="section_key" value="{{ $item->section_key }}"></div>
                                            <div class="col-md-2 fpb-7"><input type="text" class="form-control eForm-control" name="item_type" value="{{ $item->item_type }}"></div>
                                            <div class="col-md-2 fpb-7"><input type="text" class="form-control eForm-control" name="title" value="{{ $item->title }}"></div>
                                            <div class="col-md-2 fpb-7"><input type="text" class="form-control eForm-control" name="subtitle" value="{{ $item->subtitle }}"></div>
                                            <div class="col-md-2 fpb-7"><input type="text" class="form-control eForm-control" name="link" value="{{ $item->link }}"></div>
                                            <div class="col-md-1 fpb-7"><input type="number" class="form-control eForm-control" name="sort_order" value="{{ $item->sort_order }}"></div>
                                            <div class="col-md-1 fpb-7"><select name="status" class="form-control eForm-control"><option value="1" {{ $item->status ? 'selected' : '' }}>Active</option><option value="0" {{ !$item->status ? 'selected' : '' }}>Inactive</option></select></div>
                                            <div class="col-md-4 fpb-7"><textarea class="form-control eForm-control" name="description" rows="2">{{ $item->description }}</textarea></div>
                                            <div class="col-md-4 fpb-7"><textarea class="form-control eForm-control" name="content" rows="2">{{ $item->content }}</textarea></div>
                                            @include('website_management.partials.item_image_field', [
                                                'idSuffix'     => 'item-'.$item->id,
                                                // The item's own section is known here, so
                                                // the 16:9 guidance is correct immediately.
                                                'isProgramme'  => $item->item_type === 'programme'
                                                    || str_starts_with((string) $item->section_key, 'programme_catalog'),
                                                'currentImage' => $item->image,
                                            ])
                                            {{-- Featured, read back out of meta_json so the
                                                 checkbox reflects what is stored rather
                                                 than a separate source of truth. --}}
                                            @php
                                                // Decoded rather than string-matched on
                                                // '"featured":true', which would depend on
                                                // json_encode's exact spacing and break
                                                // the moment that changed.
                                                $piieItemMeta = json_decode((string) $item->meta_json, true);
                                                $piieItemFeatured = is_array($piieItemMeta)
                                                    && ! empty($piieItemMeta['featured']);
                                            @endphp
                                            <div class="col-md-2 fpb-7">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" value="1"
                                                           id="edit-item-featured-{{ $item->id }}" name="featured"
                                                           @checked($piieItemFeatured)>
                                                    <label class="form-check-label" for="edit-item-featured-{{ $item->id }}">
                                                        Featured
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-2 fpb-7"><button type="submit" class="btn-form">Update</button></div>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="col-12 mt-4">
    <div class="eSection-wrap">
        <div class="eMain">
            <p class="column-title">{{ get_phrase('SEO Settings Per Page') }}</p>
            <form method="POST" action="{{ route($routePrefix.'.seo.upsert') }}">
                @csrf
                @foreach($pages as $page)
                    <div class="row" style="padding: 12px 0; border-bottom: 1px solid #eee;">
                        <div class="col-md-2 fpb-7">
                            <label class="eForm-label">Page</label>
                            <input type="hidden" name="seo[{{ $loop->index }}][page_key]" value="{{ $page->page_key }}">
                            <input type="text" class="form-control eForm-control" value="{{ $page->title }}" readonly>
                        </div>
                        <div class="col-md-3 fpb-7">
                            <label class="eForm-label">Meta Title</label>
                            <input type="text" name="seo[{{ $loop->index }}][meta_title]" class="form-control eForm-control" value="{{ $seo[$page->page_key]->meta_title ?? '' }}">
                        </div>
                        <div class="col-md-3 fpb-7">
                            <label class="eForm-label">Meta Description</label>
                            <input type="text" name="seo[{{ $loop->index }}][meta_description]" class="form-control eForm-control" value="{{ $seo[$page->page_key]->meta_description ?? '' }}">
                        </div>
                        <div class="col-md-2 fpb-7">
                            <label class="eForm-label">Keywords</label>
                            <input type="text" name="seo[{{ $loop->index }}][meta_keywords]" class="form-control eForm-control" value="{{ $seo[$page->page_key]->meta_keywords ?? '' }}">
                        </div>
                        <div class="col-md-1 fpb-7">
                            <label class="eForm-label">Status</label>
                            <select class="form-control eForm-control" name="seo[{{ $loop->index }}][status]">
                                <option value="1" {{ !isset($seo[$page->page_key]) || $seo[$page->page_key]->status ? 'selected' : '' }}>1</option>
                                <option value="0" {{ isset($seo[$page->page_key]) && !$seo[$page->page_key]->status ? 'selected' : '' }}>0</option>
                            </select>
                        </div>
                        <div class="col-md-1 fpb-7">
                            <label class="eForm-label">Canonical</label>
                            <input type="text" name="seo[{{ $loop->index }}][canonical_url]" class="form-control eForm-control" value="{{ $seo[$page->page_key]->canonical_url ?? '' }}">
                        </div>
                    </div>
                @endforeach
                <div class="pt-2">
                    <button class="btn-form" type="submit">{{ get_phrase('Update SEO') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
