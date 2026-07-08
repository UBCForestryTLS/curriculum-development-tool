<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class UserTest extends TestCase
{
    public function test_landing_page_renders(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Curriculum MAP');
    }

    public function test_register_user(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Test Register',
            'email' => 'test.register@ubc.ca',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Test Register',
            'email' => 'test.register@ubc.ca',
        ]);
    }

    public function test_login_user(): void
    {
        $response = $this->post(route('login'), [
            'email' => 'test.register@ubc.ca',
            'password' => 'password',
        ]);

        $user = User::where('email', 'test.register@ubc.ca')->first();

        $response->assertStatus(302);
        $response->assertRedirect('home');
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_invite(): void
    {
        // InviteController has middleware(['auth', 'verified']) and its store()
        // method requires user_id in the request body. The user created via the
        // public register route does not have email_verified_at set, so mark
        // them verified here before invoking the protected endpoint.
        $user = User::where('email', 'test.register@ubc.ca')->first();
        $user->email_verified_at = Carbon::now();
        $user->save();

        $response = $this->actingAs($user)->post(route('storeInvitation'), [
            'email' => 'test.register-invite@ubc.ca',
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('invites', [
            'email' => 'test.register-invite@ubc.ca',
        ]);
    }

    public function test_recover_password(): void
    {
        // Replaced real outbound notifications with a fake collector so the
        // test does not depend on the mail driver and can assert what the
        // controller dispatched.
        Notification::fake();

        $user = User::where('email', 'test.register@ubc.ca')->first();

        $this->post(route('password.email'), [
            'email' => 'test.register@ubc.ca',
        ]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_verify_email_validates_user(): void
    {
        $user = User::where('email', 'test.register@ubc.ca')->first();
        $user->email_verified_at = null;
        $user->save();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }
}
