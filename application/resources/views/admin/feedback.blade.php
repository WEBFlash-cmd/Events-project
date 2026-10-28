@if(session('admin_success'))<p class="verification-banner" role="status">{{ session('admin_success') }}</p>@endif
@if(session('admin_error'))<p class="form-feedback is-error" role="alert">{{ session('admin_error') }}</p>@endif
@if($errors->any())<div class="form-feedback is-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
