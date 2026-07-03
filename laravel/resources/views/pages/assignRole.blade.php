@extends('layouts.app')

@section('content')
<div class="container" style="display: flex;justify-content: center;">
        <div class="row" style="width:75%">
            <div class="col-md-12 col-md-offset-1">

                <div class="card mb-5 mt-5" style="background-color: white;">
                    <div class="card-header">
                        <nav class="nav nav-pills flex-column flex-sm-row">
                            <ul class="nav nav-pills">
                                <li class="nav-item">
                                    <a class="nav-link {{ $activeTab == 'assign-role' ? 'active' : '' }}"
                                       id="pills-assign-role-tab"
                                       href="{{route('admin.assignRole.index', ['tab' => 'assign-role'])}}">Assign New Role</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ $activeTab == 'manage-roles' ? 'active' : '' }}"
                                       id="pills-manage-roles-tab"
                                       href="{{route('admin.assignRole.index', ['tab' => 'manage-roles'])}}">Manage User Roles</a>
                                </li>
                            </ul>
                        </nav>
                    </div>

                    <div class="card-body">
                        <!-- Assign Roles Content -->
                        @if($activeTab === 'assign-role')
                        <div id="assign-role-card-body">
                            <div class="form-text text-muted mb-4">
                                    <p>Enter the email address and the role you would like to assign to a person.</p>
                                        <li class="mb-1 me-4 ms-4"><strong>Admin</strong> can view, edit and manage collaborators and content for all courses and programs.</li>
                                        <li class="mb-1 me-4 ms-4"><b>Department Head</b> can view, edit and manage collaborators and content for all courses and program within assigned department.</li>
                                        <li class="mb-3 me-4 ms-4"><b>Program Director</b> can view, edit and manage collaborators and content for assigned program and its associated courses.</li>
                            </div>
                            <form action="{{ route('admin.assignRole') }}" method="POST">
                                @csrf
                                <div class="row m-2 position-relative">
                                    <div class="col-8">
                                        <input id="" type="email" name="email" class="form-control" placeholder="john.doe@ubc.ca" aria-label="email" required>
                                        <div class="invalid-tooltip">
                                            Please provide a valid email ending with ubc.ca.
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <select class="form-select" id="role" name="role">
                                            <option value="admin" selected>Admin</option>
                                            <option value="department-head">Department Head</option>
                                            <option value="program-director">Program Director</option>
                                        </select>
                                    </div>
                                </div>
                                <div id="campus-div" class="row m-2 position-relative" hidden>
                                    <div class="col-12">
                                        <select id="campus" class="form-select" name="campus">
                                            <option disabled selected hidden>Open list of campuses</option>
                                            @foreach ($campuses as $campus)
                                                <option value="{{$campus->campus}}">{{$campus->campus}}</option>
                                            @endforeach
    {{--                                        <option value="Other">Other</option>--}}
                                        </select>
                                        <input id='campus-text' class="form-control campus_text" name="campus" type="text" placeholder="Enter the campus name" disabled hidden></input>
                                        @error('campus')
                                        <span class="invalid-feedback" role="alert">
                                            <strong>{{ $message }}</strong>
                                        </span>
                                        @enderror
                                    </div>
                                </div>
                                <div id="faculty-div" class="row m-2 position-relative" hidden>
                                    <div class="col-12">
                                        <select id="faculty" class="form-select" name="faculty" disabled>
                                            <option disabled selected hidden>Open list of faculties/schools</option>
                                        </select>
                                        <input id='faculty-text' class="form-control faculty_text" name="faculty" type="text" placeholder="Enter the faculty/school" disabled hidden></input>
                                        @error('faculty')
                                        <span class="invalid-feedback" role="alert">
                                            <strong>{{ $message }}</strong>
                                        </span>
                                        @enderror
                                    </div>
                                </div>
                                <div id="department-div" class="row m-2 position-relative" hidden>
                                    <div class="col-12">
                                        <select id="department" class="form-select department_select" name="department" disabled>
                                            <option disabled selected hidden>Open list of departments</option>
                                        </select>
                                        <input id='department-text' class="form-control" name="department" type="text" placeholder="Enter the department" disabled hidden></input>
                                         @error('department')
                                        <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                        </span>
                                        @enderror
                                    </div>
                                </div>
                                <div id="program-div" class="row m-2 position-relative" hidden>
                                    <div class="col-12">
                                        <select id="program" class="form-select department_select" name="program">
                                            <option disabled selected hidden>Open list of programs</option>
                                            @foreach ($programs as $program)
                                                <option value="{{$program->program}}">{{$program->program}}</option>
                                            @endforeach
                                        </select>
                                         @error('program')
                                        <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                        </span>
                                        @enderror
                                    </div>
                                </div>
                                <div id="provideAccessAllCoursesInFaculty-div" class="row m-2 position-relative" hidden>
                                    <div class="col-12">
                                        <label>
                                        <input type="checkbox" value="1" name="accessToAllCoursesInFaculty">
                                            Provide access to all courses in faculty
                                        </label>
                                    </div>
                                </div>
                                <div class="row m-2 position-relative">
                                    <div class="col-12">
                                        <button id="" type="submit" class="btn btn-primary col"><i class="bi bi-plus"></i> User</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        @elseif($activeTab === 'manage-roles')
                        <!-- Manage Roles Content -->
                        <div id="manage-roles-card-body">
                            <form class="form-inline my-2 my-lg-0 w-100" method="GET" action="{{ route('admin.getUserRoles') }}">
                                @csrf
                                <div class="d-flex w-100">
                                    <input name='userEmail' class="form-control flex-grow-1 me-2" placeholder="john.doe@ubc.ca" aria-label="email" required>
                                    <div class="invalid-tooltip">
                                        Please provide a valid email.
                                    </div>
                                    <button class="btn btn-outline-primary my-2 my-sm-0" type="submit">Search</button>
                                </div>
                            </form>
                            @if(session('error'))
                                <div class="mt-3 text-danger">{{ session('error') }}</div>
                            @endif

                            @if(session('user'))
                                <div class="mt-4">
                                    <b>User Name:</b> {{ session('user')->name}} <br>
                                    <b>User Email:</b> {{ session('user')->email}}
                                </div>
                                <table id="" class="table table-light borderless mt-2" >
                                    <thead>
                                    <tr class="table-primary">
                                        <th>Role(s)</th>
                                        <th>Department/Program Name</th>
                                        <th colspan="2" class="text-center w-25">Action</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach(session('roles') as $role)
                                        @if($role->role === 'department head')
                                            @if(session('departmentsHeaded')->count() < 1)
                                                <tr>
                                                    <td>
                                                        {{ucwords($role->role)}}
                                                    </td>
                                                    <td>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-danger btn"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#confirmRemoveModal"
                                                                data-role-id="{{ $role->id }}"
                                                                data-role-name = "{{ucwords($role->role)}}"
                                                                data-user-id="{{ session('user')->id }}"
                                                                data-user-name = "{{ucwords(session('user')->name)}}"
                                                                data-context="department"
                                                                data-context-id="{{ null }}"
                                                                data-action="{{route('admin.assignRole.deleteDepartmentHeadRoleUnassignedDepartment',
                                                            ['user' => session('user')->id, 'role' => $role->id, 'department' => null])}}">Remove</button>

                                                    </td>
                                                </tr>
                                            @endif
                                            @foreach(session('departmentsHeaded') as $department)
                                                <tr>
                                                    <td>
                                                        {{ucwords($role->role)}}
                                                    </td>
                                                    <td>
                                                        {{$department->department}}
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-danger btn"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#confirmRemoveModal"
                                                                data-role-id="{{ $role->id }}"
                                                                data-role-name = "{{ucwords($role->role)}}"
                                                                data-user-id="{{ session('user')->id }}"
                                                                data-user-name = "{{ucwords(session('user')->name)}}"
                                                                data-context="department"
                                                                data-context-id="{{ $department->department_id }}"
                                                                data-action="{{route('admin.assignRole.deleteDepartmentHeadRole',
                                                            ['user' => session('user')->id, 'role' => $role->id, 'department' => $department->department_id])}}">Remove</button>

                                                    </td>
                                                </tr>
                                            @endforeach
                                        @elseif($role->role === 'program director')
                                            @if(session('directedPrograms')->count() < 1)
                                                <tr>
                                                    <td>
                                                        {{ucwords($role->role)}}
                                                    </td>
                                                    <td>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-danger btn"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#confirmRemoveModal"
                                                                data-role-id="{{ $role->id }}"
                                                                data-role-name = "{{ucwords($role->role)}}"
                                                                data-user-id="{{ session('user')->id }}"
                                                                data-user-name = "{{ucwords(session('user')->name)}}"
                                                                data-context="program"
                                                                data-context-id="{{ null }}"
                                                                data-action="{{route('admin.assignRole.deleteProgramDirectorRoleUnassignedProgram',
                                                            ['user' => session('user')->id, 'role' => $role->id, 'program' => null])}}">Remove</button>
                                                    </td>
                                                </tr>
                                            @endif
                                            @foreach(session('directedPrograms') as $program)
                                                <tr>
                                                    <td>
                                                        {{ucwords($role->role)}}
                                                    </td>
                                                    <td>
                                                        {{$program->program}}
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-danger btn"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#confirmRemoveModal"
                                                                data-role-id="{{ $role->id }}"
                                                                data-role-name = "{{ucwords($role->role)}}"
                                                                data-user-id="{{ session('user')->id }}"
                                                                data-user-name = "{{ucwords(session('user')->name)}}"
                                                                data-context="program"
                                                                data-context-id="{{ $program->program_id }}"
                                                                data-action="{{route('admin.assignRole.deleteProgramDirectorRole',
                                                            ['user' => session('user')->id, 'role' => $role->id, 'program' => $program->program_id])}}">Remove</button>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @else
                                            <tr>
                                                <td>{{ucwords($role->role)}}</td>
                                                <td></td>
                                                <td>
                                                    @if($role->role !== 'user')
                                                        <button type="button" class="btn btn-danger btn"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#confirmRemoveModal"
                                                                data-role-id="{{ $role->id }}"
                                                                data-role-name = "{{ucwords($role->role)}}"
                                                                data-user-id="{{ session('user')->id }}"
                                                                data-user-name = "{{ucwords(session('user')->name)}}"
                                                                data-context="admin"
                                                                data-action="{{route('admin.assignRole.deleteAdminRole',
                                                            ['user' => session('user')->id, 'role' => $role->id])}}">Remove</button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                    </tbody>
                                </table>

                                <!-- Remove Role Confirmation Modal -->
                                <div class="modal fade" id="confirmRemoveModal"
                                     tabindex="-1" role="dialog"
                                     aria-labelledby="confirmRemoveModalLabel"
                                     aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered" role="document">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="exampleModalLabel">Remove Role
                                                    Confirmation</h5>
{{--                                                <button type="button" class="btn-close" data-bs-dismiss="modal"--}}
{{--                                                        aria-label="Close">--}}
{{--                                                    <span aria-hidden="true">&times;</span>--}}
{{--                                                </button>--}}
                                            </div>
                                            <div class="modal-body">Are you sure you want to
                                                remove <strong id="modalRoleName"></strong> role for
                                                <strong id="modalUserName"></strong>?
                                            </div>
                                            <form method="POST"
                                                  id="removeRoleForm">
                                                @csrf
                                                @method('DELETE')
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger">Yes</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>


    <script type="application/javascript">
    var faculties = <?php echo json_encode($faculties);?>;
    var vFaculties = faculties.filter(item => {
        return item.campus_id === 1;
    });
    var oFaculties = faculties.filter(item => {
        return item.campus_id === 2;
    });
    var departments = <?php echo json_encode($departments);?>;

    $(document).ready(function () {

        $('#confirmRemoveModal').on('show.bs.modal', function (event) {
            const button = $(event.relatedTarget);
            const roleId = button.data('role-id');
            const roleName = button.data('role-name');
            const context = button.data('context');
            const contextId = button.data('context-id');
            const userId = button.data('user-id');
            const userName = button.data('user-name');
            const actionUrl = button.data('action');

            $('#modalRoleName').text(roleName);
            $('#modalUserName').text(userName);

            $('#removeRoleForm').attr('action', actionUrl);

        });

        $('#pills-manage-roles-tab').on('click', function (){
            $('#pills-manage-roles-tab').addClass('active');
            $('#pills-assign-role-tab').removeClass('active');

            $('#assign-role-card-body').addClass('d-none');
            $('#manage-roles-card-body').removeClass('d-none');

        });

        $('#pills-assign-role-tab').on('click', function (){
            $('#pills-assign-role-tab').addClass('active');
            $('#pills-manage-roles-tab').removeClass('active');

            $('#assign-role-card-body').removeClass('d-none');
            $('#manage-roles-card-body').addClass('d-none');

        });

        $('#role').change(function() {
            if($('#role').find(':selected').val() == 'department-head') {
                roleDpHeadOption();
            } else if($('#role').find(':selected').val() == 'program-director') {
                roleProgramDirOption();
            }
        });

        $('#campus').change( function() {
            // filter faculty based on campus
            if ($('#campus').find(':selected').text() == 'Vancouver') {
                // Hide text / show select
                campusDefaultOption();

                //Displays Vancouver Faculties
                // delete drop down items
                $('#faculty').empty();
                // populate drop down
                $('#faculty').append($('<option disabled selected hidden>Open list of faculties/schools</option>'));
                vFaculties.forEach (faculty => $('#faculty').append($('<option name="'+faculty.faculty_id+'" />').val(faculty.faculty).text(faculty.faculty)));
                // $('#faculty').append($('<option name="-1" />').val('Other').text('Other'));

                // enable the faculty select field
                if ($('#faculty').is(':disabled')) {
                    $('#faculty').prop('disabled', false);
                }
                // disable the department field
                if (!($('#department').is(':disabled'))) {
                    $('#department').empty();
                    $('#department').append($('<option disabled selected hidden>Open list of departments</option>'));
                    $('#department').prop('disabled', true);
                }

            } else if ($('#campus').find(':selected').text() == 'Okanagan') {
                // Hide text / show select
                campusDefaultOption();

                // Display Okangan Faculties
                // delete drop down items
                $('#faculty').empty();
                // populate drop down
                $('#faculty').append($('<option disabled selected hidden>Open list of faculties/schools</option>'));
                oFaculties.forEach (faculty => $('#faculty').append($('<option name="'+faculty.faculty_id+'" />').val(faculty.faculty).text(faculty.faculty)));
                // $('#faculty').append($('<option name="-1" />').val('Other').text('Other'));

                // enable the faculty select field
                if ($('#faculty').is(':disabled')) {
                    $('#faculty').prop('disabled', false);
                }
                // disable the department field
                if (!($('#department').is(':disabled'))) {
                    $('#department').empty();
                    $('#department').append($('<option disabled selected hidden>Open list of departments</option>'));
                    $('#department').prop('disabled', true);
                }

            } else {
                campusOtherOption();
            }

        });

        $('#faculty').change( function() {
            var facultyId = parseInt($('#faculty').find(':selected').attr('name'));

            // get departments by faculty if they belong to a faculty, else display all departments
            if (facultyId >= 0) {
                // Hide text / show select
                facultyDefaultOption();

                // delete drop down items
                $('#department').empty();
                // populate drop down
                $('#department').append($('<option disabled selected hidden>Open list of departments</option>'));
                var filteredDepartments = departments.filter(item => {
                    return item.faculty_id === facultyId;
                });
                filteredDepartments.forEach(department => $('#department').append($('<option />').val(department.department).text(department.department)));


                // $('#department').append($('<option />').val('Other').text('Other'));

                // enable the faculty select field
                if ($('#department').is(':disabled')) {
                    $('#department').prop('disabled', false);
                }

            } else {
                // Hide text / show select
                facultyOtherOption();
            }

        });

        $('#department').change( function() {
            if ($('#department').find(':selected').val() !== 'Other') {
                departmentDefaultOption();
            } else {
                departmentOtherOption();
            }
        });
    });

    function roleDpHeadOption() {
        // Hide text / show select
        $('#campus-div').prop( "hidden", false );
        $('#faculty-div').prop( "hidden", false );
        $('#department-div').prop( "hidden", false );
        $('#program-div').prop( "hidden", true );
        $('#campus').prop( "hidden", false );
        $('#campus').prop( "required", true );
        $('#campus-text').prop( "hidden", true );
        $('#campus-text').prop( "disabled", true );
        $('#faculty').prop( "hidden", false );
        $('#faculty').prop( "required", true );
        $('#faculty').prop( "disabled", true );
        $('#faculty-text').prop( "hidden", true );
        $('#faculty-text').prop( "disabled", true );
        $('#department').prop( "hidden", false );
        $('#department').prop( "required", true );
        $('#department').prop( "disabled", true );
        $('#department-text').prop( "hidden", true );
        $('#department-text').prop( "disabled", true );
        $('#program').prop( "hidden", true );
        $('#provideAccessAllCoursesInFaculty-div').prop("hidden", false);

    }

    function roleProgramDirOption() {
        // Hide text / show select
        $('#campus-div').prop( "hidden", true );
        $('#faculty-div').prop( "hidden", true );
        $('#department-div').prop( "hidden", true );
        $('#program-div').prop( "hidden", false );
        $('#campus').prop( "hidden", true );
        $('#campus-text').prop( "hidden", true );
        $('#campus-text').prop( "disabled", true );
        $('#faculty').prop( "hidden", true );
        $('#faculty').prop( "disabled", true );
        $('#faculty-text').prop( "hidden", true );
        $('#faculty-text').prop( "disabled", true );
        $('#department').prop( "hidden", true );
        $('#department').prop( "disabled", true );
        $('#department-text').prop( "hidden", true );
        $('#department-text').prop( "disabled", true );
        $('#program').prop( "hidden", false );
        $('#program').prop( "required", true );
        $('#provideAccessAllCoursesInFaculty-div').prop("hidden", false);
    }

    function departmentDefaultOption() {
        // Hide text / show select
        $('#department-text').prop( "hidden", true );
        $('#department-text').prop( "disabled", true );
    }

    function departmentOtherOption() {
        // Hide text / show select
        $('#department-text').prop( "hidden", false );
        $('#department-text').prop( "disabled", false );
    }

    function facultyDefaultOption() {
        // Hide text / show select
        $('#faculty-text').prop( "hidden", true );
        $('#faculty-text').prop( "disabled", true );
        $('#department').prop( "hidden", false );
        $('#department').prop( "disabled", false );
        $('#department-text').prop( "hidden", true );
        $('#department-text').prop( "disabled", true );
    }

    function facultyOtherOption() {
        // Hide text / show select
        $('#faculty-text').prop( "hidden", false );
        $('#faculty-text').prop( "disabled", false );
        $('#department').prop( "disabled", true );
        $('#department').prop( "hidden", true );
        $('#department').text('');
        $('#department-text').prop( "hidden", false );
        $('#department-text').prop( "disabled", false );
    }


    function campusDefaultOption() {
        // Hide text / show select
        $('#campus-text').prop( "hidden", true );
        $('#campus-text').prop( "disabled", true );
        $('#faculty').prop( "hidden", false );
        $('#faculty').prop( "disabled", false );
        $('#faculty-text').prop( "hidden", true );
        $('#faculty-text').prop( "disabled", true );
        $('#department').prop( "hidden", false );
        $('#department').prop( "disabled", false );
        $('#department-text').prop( "hidden", true );
        $('#department-text').prop( "disabled", true );
    }

    function campusOtherOption() {
        // Hide text / show select
        $('#campus-text').prop( "hidden", false );
        $('#campus-text').prop( "disabled", false );
        $('#faculty').prop( "disabled", true );
        $('#faculty').prop( "hidden", true );
        $('#faculty').text('');
        $('#faculty-text').prop( "hidden", false );
        $('#faculty-text').prop( "disabled", false );
        $('#department').prop( "disabled", true );
        $('#department').prop( "hidden", true );
        $('#department').text('');
        $('#department-text').prop( "hidden", false );
        $('#department-text').prop( "disabled", false );
    }
</script>
@endsection
