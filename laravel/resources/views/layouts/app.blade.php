@use('Illuminate\Support\Facades\Vite')

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <script src="{{ asset('vendor/jquery.min.js') }}"></script>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <script src="https://code.iconify.design/2/2.0.3/iconify.min.js"></script>

    <!-- Fonts -->
    <link rel="dns-prefetch" href="//fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css?family=Nunito" rel="stylesheet">

    <!-- Styles -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.3/font/bootstrap-icons.css">
    @vite('resources/js/bootstrap.js')
    @vite('resources/sass/app.scss')
</head>
<!-- Google Analytics -->
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-GLBX8NCRRE"></script>
<script>
    window.dataLayer = window.dataLayer || [];
    function gtag() { dataLayer.push(arguments); }
    gtag('js', new Date());

    gtag('config', 'G-GLBX8NCRRE');
</script>

<body>
    <div id="app">
        <div class="container mb-5">
            <nav
                class="navbar fixed-top navbar-expand-md navbar-dark bg-primary mb-4 border-bottom border-white border-5">
                <div class="container">
                    <a class="navbar-brand" href="{{ url('/') }}">
                        <img width="29" height="40" src="{{ asset('img/ubc-logo-2018-crest-white-rgb72.png') }}"
                            alt="UBC crest logo" style="margin-right: 25px">
                        <strong class="text-white" style="font-family: Arial, Helvetica, sans-serif">Curriculum
                            MAP</strong>
                    </a>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                        data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
                        aria-expanded="false" aria-label="{{ __('Toggle navigation') }}">
                        <span class="navbar-toggler-icon"></span>
                    </button>

                    <div class="collapse navbar-collapse" id="navbarSupportedContent">
                        <!-- Left Side Of Navbar -->
                        <ul class="navbar-nav me-auto">
                            <li class="nav-item" style="margin-left: 15px;">
                                <a class="nav-link text-white" href="{{ route('about') }}">About</a>
                            </li>
                            <li class="nav-item" style="margin-left: 15px;">
                                <a class="nav-link text-white" href="{{ route('FAQ') }}">FAQ</a>
                            </li>
                        </ul>

                        <!-- Right Side Of Navbar -->
                        <ul class="navbar-nav ms-auto">
                            <!-- Authentication Links -->

                            @guest
                                <li class="nav-item">
                                    <a class="nav-link text-white" href="{{ route('login') }}">{{ __('Login') }}</a>
                                </li>
                                @if (Route::has('register'))
                                    <li class="nav-item">
                                        <a class="nav-link text-white" href="{{ route('register') }}">{{ __('Register') }}</a>
                                    </li>
                                @endif
                            @else

                                <li class="nav-item">
                                    <a class="nav-link text-white" href="{{ route('home')}}">My Dashboard</a>
                                </li>



                                <li class="nav-item">
                                    <form name="SyllabusSubmit" action="{{route('syllabus')}}" method="GET">
                                        <input type="hidden" name="syllabus_id" value="">
                                        <a class="nav-link text-white" href="#"
                                            onclick="document.SyllabusSubmit.submit();">Syllabus Generator</a>
                                    </form>

                                </li>

                                <li class="nav-item dropdown">
                                    <a id="navbarDropdown" class="nav-link dropdown-toggle text-white" href="#"
                                        role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
                                        v-pre>
                                        {{ Auth::user()->email }}
                                    </a>

                                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="navbarDropdown">


                                        @can('admin-privilege')
                                            <a class="dropdown-item" href="{{ url('/admin') }}">
                                                System Administrator
                                            </a>
                                        @endcan

                                        @can('admin-privilege')
                                            <a class="dropdown-item" href="{{ route('admin.assignRole.index') }}">
                                                Manage Roles
                                            </a>
                                        @endcan

                                        @can('admin-privilege')
                                            <a class="dropdown-item" href="{{ route('email') }}">
                                                Email Tool
                                            </a>
                                        @endcan

                                        <a class="dropdown-item" href="{{ route('accountInformation') }}">
                                            Account Information
                                        </a>

                                        <a class="dropdown-item" href="{{ route('requestInvitation') }}">
                                            Registration invite
                                        </a>

                                        <a class="dropdown-item" href="{{ route('logout') }}" onclick="event.preventDefault();
                                                                document.getElementById('logout-form').submit();">
                                            {{ __('Logout') }}
                                        </a>

                                        <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                            @csrf
                                        </form>
                                    </div>
                                </li>
                            @endguest
                        </ul>
                    </div>
                </div>
            </nav>
        </div>
        <div class="container bg-body">
            <main class="py-4">
                @include('partials.alerts')
                @yield('content')

            </main>

        </div>
    </div>
    <div>
        <template shadowrootmode="open">
            <link href="//cdn.ubc.ca/clf/7.0.4/css/ubc-clf-full-bw.min.css" rel="stylesheet">
            @include('partials.footer')
        </template>
    </div>
    {{--@vite(['resources/js/app.js'])--}}
</body>

</html>
