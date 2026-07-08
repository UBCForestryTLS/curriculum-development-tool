@extends('layouts.app')

@section('content')
    <div class="container" style="display: flex;justify-content: center;">
        <div class="row" style="width:75%">
            <div class="col-md-12 col-md-offset-1">

                <div class="card mb-5 mt-5" style="background-color: white;">
                    <div class="card-header">
                        <b>Send invitation:</b>
                    </div>

                    <div class="card-body">
                        <p class="form-text text-muted">Enter the email address of the person you would like to invite to use this website.
                            Once they register, you may add them as collaborators to your courses/programs.</p>

                        <form class="form-horizontal" method="POST" action="{{ route('storeInvitation') }}">
                            {{ csrf_field() }}

                            <div class="mb-3{{ $errors->has('email') ? ' has-error' : '' }}">
                                <label for="email" class="col-md-4 control-label">E-Mail Address:</label>

                                <div class="col-md-8">
                                    <input id="email" type="email" class="form-control" name="email" value="{{ old('email') }}" required autofocus>
                                    <input type="hidden" class="form-check-input" name="user_id" value={{Auth::id()}}>
                                    <input type="hidden" class="form-check-input" name="type" value="unassigned">

                                    @if ($errors->has('email'))
                                        <span class="help-block">
                                            <strong>{{ $errors->first('email') }}</strong>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="col-md-8 col-md-offset-4">
                                    <button type="submit" class="btn btn-primary">
                                        Send An Invitation
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card mb-5 mt-5" style="background-color: white;">
                    <div class="card-body" style="padding:0%;">
                        @if(count($invites)>0)
                            <table class="table table-hover dashBoard">
                                <thead>
                                  <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Invited Email</th>
                                    <th scope="col">Date and Time</th>
                                  </tr>
                                </thead>

                                @foreach ($invites as $index => $invite)
                                <tbody>
                                  <tr>
                                    <th scope="row">{{$index + 1}}</th>
                                    <td>{{$invite->email}}</td>
                                    <td>{{$invite->updated_at}}</td>
                                  </tr>
                                </tbody>
                                @endforeach
                            </table>

                        @else

                        <div>
                            <span>There are no invites</span>
                        </div>

                        @endif
                </div>
                </div>
            </div>
        </div>
    </div>
@endsection
