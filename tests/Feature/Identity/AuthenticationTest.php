<?php

namespace Tests\Feature\Identity;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** AUTH-001..006, IAM-006 */
class AuthenticationTest extends TestCase
{
    public function test_directory_user_signs_in_and_is_provisioned_without_admin_rights(): void
    {
        $this->directory->add('asharma', 'Right-Password-1', ['category' => 'faculty', 'employeeId' => 'E1001']);

        $this->from('/login')->post('/login', ['username' => 'ASharma ', 'password' => 'Right-Password-1'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $user = User::query()->where('username', 'asharma')->firstOrFail();
        $this->assertSame('ldap', $user->auth_source);
        $this->assertSame('faculty', $user->category);
        $this->assertSame('E1001', $user->employee_id);
        $this->assertNull($user->password, 'directory passwords are never stored');
        $this->assertSame(['employee'], $user->getRoleNames()->all());
        $this->assertFalse($user->can('admin.access'));
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login', 'outcome' => 'success', 'actor_id' => $user->id]);
    }

    public function test_wrong_password_fails_generically_and_is_audited_without_the_password(): void
    {
        $this->directory->add('asharma', 'Right-Password-1');

        $this->from('/login')->post('/login', ['username' => 'asharma', 'password' => 'Wrong-Secret-xyz'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['username' => __('auth.failed')]);

        $this->assertGuest();
        $event = AuditEvent::query()->where('action', 'auth.login')->sole();
        $this->assertSame('failure', $event->outcome);
        $this->assertSame('invalid_credentials', $event->context['reason']);
        $this->assertStringNotContainsString('Wrong-Secret-xyz', json_encode(DB::table('audit_events')->get()));
    }

    public function test_unknown_user_gets_the_same_message_as_a_wrong_password(): void
    {
        $this->post('/login', ['username' => 'nobody', 'password' => 'whatever-123'])
            ->assertSessionHasErrors(['username' => __('auth.failed')]);
    }

    public function test_sign_in_fails_closed_when_the_directory_is_down(): void
    {
        $this->directory->add('asharma', 'Right-Password-1');
        // Even a previously provisioned user cannot sign in without the directory.
        User::factory()->create(['email' => 'a@institute.test'])->forceFill(['username' => 'asharma'])->save();
        $this->directory->down = true;

        $this->post('/login', ['username' => 'asharma', 'password' => 'Right-Password-1'])
            ->assertSessionHasErrors(['username' => __('auth.directory_unavailable')]);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login', 'outcome' => 'failure']);
    }

    public function test_disabled_directory_account_cannot_sign_in(): void
    {
        $this->directory->add('former', 'Right-Password-1', ['disabled' => true]);

        $this->post('/login', ['username' => 'former', 'password' => 'Right-Password-1'])
            ->assertSessionHasErrors(['username' => __('auth.inactive')]);
        $this->assertGuest();
    }

    public function test_locally_deactivated_user_cannot_sign_in(): void
    {
        $this->directory->add('asharma', 'Right-Password-1');
        User::factory()->inactive()->create()->forceFill(['username' => 'asharma'])->save();

        $this->post('/login', ['username' => 'asharma', 'password' => 'Right-Password-1'])
            ->assertSessionHasErrors(['username' => __('auth.inactive')]);
        $this->assertGuest();
    }

    public function test_local_account_signs_in_with_its_own_password(): void
    {
        $vendor = User::factory()->local('Vendor-Password-2026')->create(['category' => 'vendor']);

        $this->post('/login', ['username' => $vendor->username, 'password' => 'Vendor-Password-2026'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($vendor);
    }

    public function test_directory_entry_cannot_take_over_a_local_account(): void
    {
        $vendor = User::factory()->local('Vendor-Password-2026')->create();
        $this->directory->add($vendor->username, 'Directory-Pass-1');

        // The directory password does not work for a local account.
        $this->post('/login', ['username' => $vendor->username, 'password' => 'Directory-Pass-1'])
            ->assertSessionHasErrors('username');
        $this->assertSame('local', $vendor->fresh()->auth_source);
    }

    public function test_empty_password_is_rejected_by_validation(): void
    {
        $this->directory->add('asharma', '');

        $this->post('/login', ['username' => 'asharma', 'password' => ''])->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_repeated_failures_are_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->post('/login', ['username' => 'asharma', 'password' => 'bad-password'])->assertStatus(302);
        }
        $this->post('/login', ['username' => 'asharma', 'password' => 'bad-password'])->assertStatus(429);
    }

    public function test_sign_in_regenerates_the_session_and_logout_is_audited(): void
    {
        $this->directory->add('asharma', 'Right-Password-1');
        $this->post('/login', ['username' => 'asharma', 'password' => 'Right-Password-1']);
        $user = User::query()->where('username', 'asharma')->sole();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.logout', 'actor_id' => $user->id]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/admin/users')->assertRedirect(route('login'));
    }
}
