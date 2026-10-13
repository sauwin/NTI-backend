<?php

use App\Models\Application;
use App\Models\Call;
use App\Models\CallEvaluator;
use App\Models\GdprConsent;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class); 

// --- helpers ---

function auditRole(string $slug): Role
{
    return Role::firstOrCreate(['slug' => $slug], ['name' => $slug, 'description' => $slug]);
}

function auditUser(string $roleSlug): User
{
    $user = User::factory()->create();
    $role = auditRole($roleSlug);
    $user->roles()->attach($role->id, ['granted_by' => $user->id, 'granted_at' => now()]);
    return $user;
}

function auditCallWithApp(): array
{
    $admin = auditUser('nti_admin');
    $student = auditUser('student');
    $profile = StudentProfile::factory()->create(['user_id' => $student->id]);

    $call = Call::factory()->create(['program' => 'a', 'status' => 'draft']);

    $app = Application::factory()->create([
        'student_profile_id' => $profile->id,
        'call_id' => $call->id,
        'status' => 'submitted',
    ]);

    return [$admin, $student, $call, $app];
}

// --- ApplicationController::updateStatus ---

test('updateStatus writes audit log', function () {
    [$admin, , , $app] = auditCallWithApp();

    $this->actingAs($admin)
        ->patchJson("/api/admin/applications/{$app->id}/status", ['status' => 'formally_verified'])
        ->assertStatus(200);

    $this->assertDatabaseHas('audit', [
        'action' => 'update_status',
        'object' => 'application',
    ]);
});

test('updateStatus audit log contains application_id and old/new status', function () {
    [$admin, , , $app] = auditCallWithApp();
    $app->update(['status' => 'submitted']);

    $this->actingAs($admin)
        ->patchJson("/api/admin/applications/{$app->id}/status", ['status' => 'under_evaluation']);

    $row = DB::table('audit')->where('action', 'update_status')->latest('id')->first();
    $details = json_decode($row->details, true);

    expect($details['application_id'])->toBe($app->id);
    expect($details['old_status'])->toBe('submitted');
    expect($details['new_status'])->toBe('under_evaluation');
});

// --- CallEvaluatorController::assign ---

test('assign evaluator writes audit log', function () {
    [$admin, , $call] = auditCallWithApp();
    $evaluator = auditUser('evaluator');

    $this->actingAs($admin)
        ->postJson("/api/admin/calls/{$call->id}/evaluators", ['user_id' => $evaluator->id])
        ->assertStatus(201);

    $this->assertDatabaseHas('audit', [
        'action' => 'assign_evaluator',
        'object' => 'call',
    ]);
});

test('assign evaluator audit log contains call_id and user_id', function () {
    [$admin, , $call] = auditCallWithApp();
    $evaluator = auditUser('evaluator');

    $this->actingAs($admin)
        ->postJson("/api/admin/calls/{$call->id}/evaluators", ['user_id' => $evaluator->id]);

    $row = DB::table('audit')->where('action', 'assign_evaluator')->latest('id')->first();
    $details = json_decode($row->details, true);

    expect($details['call_id'])->toBe($call->id);
    expect($details['user_id'])->toBe($evaluator->id);
});

// --- CallEvaluatorController::remove ---

test('remove evaluator writes audit log', function () {
    [$admin, , $call] = auditCallWithApp();
    $evaluator = auditUser('evaluator');
    CallEvaluator::create(['call_id' => $call->id, 'user_id' => $evaluator->id]);

    $this->actingAs($admin)
        ->deleteJson("/api/admin/calls/{$call->id}/evaluators/{$evaluator->id}")
        ->assertStatus(200);

    $this->assertDatabaseHas('audit', [
        'action' => 'remove_evaluator',
        'object' => 'call',
    ]);
});

// --- CallController::store ---

test('create call writes audit log', function () {
    $admin = auditUser('nti_admin');

    $this->actingAs($admin)
        ->postJson('/api/admin/calls', [
            'program_type' => 'a',
            'name' => 'Test Call',
            'status' => 'draft',
            'deadline_at' => now()->addDays(14)->toDateString(),
        ])
        ->assertStatus(201);

    $this->assertDatabaseHas('audit', [
        'action' => 'create_call',
        'object' => 'call',
    ]);
});

// --- CallController::destroy ---

test('delete call writes audit log', function () {
    [$admin, , $call] = auditCallWithApp();

    $this->actingAs($admin)
        ->deleteJson("/api/admin/calls/{$call->id}")
        ->assertStatus(200);

    $this->assertDatabaseHas('audit', [
        'action' => 'delete_call',
        'object' => 'call',
    ]);
});

test('delete call audit log contains call_id', function () {
    [$admin, , $call] = auditCallWithApp();
    $callId = $call->id;

    $this->actingAs($admin)
        ->deleteJson("/api/admin/calls/{$callId}");

    $row = DB::table('audit')->where('action', 'delete_call')->latest('id')->first();
    $details = json_decode($row->details, true);

    expect($details['call_id'])->toBe($callId);
});

// --- GdprController::export ---

test('gdpr export writes audit log', function () {
    $user = auditUser('student');
    GdprConsent::create([
        'user_id' => $user->id,
        'purpose' => 'marketing',
        'version' => '1.0',
        'ip_address' => '127.0.0.1',
        'consented_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson('/api/gdpr/export')
        ->assertStatus(200);

    $this->assertDatabaseHas('audit', [
        'action' => 'gdpr_export',
        'object' => 'user',
    ]);
});

// --- GdprController::anonymize ---

test('gdpr anonymize writes audit log', function () {
    $user = auditUser('student');

    $this->actingAs($user)
        ->deleteJson('/api/gdpr/account', ['confirm' => 'DELETE MY ACCOUNT'])
        ->assertStatus(200);

    $this->assertDatabaseHas('audit', [
        'action' => 'gdpr_anonymize',
        'object' => 'user',
    ]);
});

test('gdpr anonymize audit log contains target_user_id', function () {
    $user = auditUser('student');
    $userId = $user->id;

    $this->actingAs($user)
        ->deleteJson('/api/gdpr/account', ['confirm' => 'DELETE MY ACCOUNT']);

    $row = DB::table('audit')->where('action', 'gdpr_anonymize')->latest('id')->first();
    $details = json_decode($row->details, true);

    expect($details['target_user_id'])->toBe($userId);
});

// --- ip_address is always written ---

test('audit rows always have ip_address', function () {
    [$admin, , , $app] = auditCallWithApp();

    $this->actingAs($admin)
        ->patchJson("/api/admin/applications/{$app->id}/status", ['status' => 'formally_verified']);

    $row = DB::table('audit')->where('action', 'update_status')->latest('id')->first();

    expect($row->ip_address)->not->toBeNull();
});
