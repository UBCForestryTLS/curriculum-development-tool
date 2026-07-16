@if (session('errorMessages'))
    @foreach(session('errorMessages') as $errMsg)
        <div class="alert alert-danger" role="alert">
            {!! $errMsg !!}
        </div>
    @endforeach
@endif

@if (session('warningMessages'))
    @foreach(session('warningMessages') as $warningMsg)
        <div class="alert alert-warning" role="alert">
            {!! $warningMsg !!}
        </div>
    @endforeach
@endif

@if (session('successMessages'))
    @foreach(session('successMessages') as $successMsg)
        <div class="alert alert-success" role="alert">
            {!! $successMsg !!}
        </div>
    @endforeach
@endif

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show py-2 px-3 mx-auto auto-dismiss-alert" style="max-width: 780px;" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close top-50 translate-middle-y" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

@if(session('warning'))
    <div class="alert alert-warning" role="alert">
        {{ session('warning') }}
    </div>
@endif


@if(session('error'))
    <div class="alert alert-danger" role="alert">
        {{ session('error') }}
    </div>
@endif

@if (count($errors) > 0) 
    @foreach($errors->all() as $error)
        <div class="alert alert-danger">
            {{$error}}
        </div>
    @endforeach
@endif

<script>
    document.addEventListener('DOMContentLoaded', function () {
        window.setTimeout(function () {
            document.querySelectorAll('.auto-dismiss-alert').forEach(function (alert) {
                alert.classList.remove('show');
                window.setTimeout(function () {
                    alert.remove();
                }, 150);
            });
        }, 4000);
    });
</script>
