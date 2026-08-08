<?php

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditEvent;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\ActivityLogStatus;

/*
 * RecordAudit is the ledger's only audit entry point, so what matters here is
 * that it cannot quietly do nothing — every other guarantee in the application
 * assumes an audit row exists for each state change.
 */

it('records the event, subject, and properties', function () {
    $subject = User::factory()->create();

    $activity = app(RecordAudit::class)->handle(
        event: AuditEvent::Posted,
        subject: $subject,
        properties: ['entry_number' => 'JE-2026-0142'],
        description: 'Posted JE-2026-0142',
        logName: 'ledger',
    );

    expect($activity->event)->toBe('posted')
        ->and($activity->description)->toBe('Posted JE-2026-0142')
        ->and($activity->log_name)->toBe('ledger')
        ->and($activity->properties->get('entry_number'))->toBe('JE-2026-0142')
        ->and($activity->subject_id)->toBe($subject->getKey());
});

it('stores the morph alias rather than the class name for the subject', function () {
    // A stored FQCN is a rename away from an unreadable audit trail, which is
    // why AppServiceProvider enforces a morph map.
    $activity = app(RecordAudit::class)->handle(
        event: AuditEvent::Created,
        subject: User::factory()->create(),
    );

    expect($activity->subject_type)->toBe('user');
});

it('attributes the event to the authenticated user by default', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $activity = app(RecordAudit::class)->handle(
        event: AuditEvent::Closed,
        subject: User::factory()->create(),
    );

    expect($activity->causer_id)->toBe($user->getKey())
        ->and($activity->causer_type)->toBe('user');
});

it('accepts an explicit causer for runs with no authenticated user', function () {
    $causer = User::factory()->create();

    $activity = app(RecordAudit::class)->handle(
        event: AuditEvent::Reversed,
        subject: User::factory()->create(),
        causer: $causer,
    );

    expect($activity->causer_id)->toBe($causer->getKey());
});

it('leaves the causer null for console runs with nobody authenticated', function () {
    $activity = app(RecordAudit::class)->handle(
        event: AuditEvent::Posted,
        subject: User::factory()->create(),
    );

    expect($activity->causer_id)->toBeNull();
});

it('falls back to the event name when no description is given', function () {
    $activity = app(RecordAudit::class)->handle(
        event: AuditEvent::Archived,
        subject: User::factory()->create(),
    );

    expect($activity->description)->toBe('archived');
});

it('throws rather than skipping the audit when logging is disabled', function () {
    // The whole reason RecordAudit exists as a wrapper: ActivityLogger::log()
    // returns null and writes nothing here, which would let an entry post with
    // no attribution at all.
    app(ActivityLogStatus::class)->disable();

    app(RecordAudit::class)->handle(
        event: AuditEvent::Posted,
        subject: User::factory()->create(),
    );
})->throws(RuntimeException::class, 'attribution is not optional in a ledger');

it('writes nothing when the surrounding transaction rolls back', function () {
    // Attribution has to be part of the change, not a note about it.
    $subject = User::factory()->create();

    try {
        DB::transaction(function () use ($subject) {
            app(RecordAudit::class)->handle(event: AuditEvent::Posted, subject: $subject);

            throw new RuntimeException('posting failed');
        });
    } catch (RuntimeException) {
        // Expected — the point is what survives the rollback.
    }

    expect(Activity::query()->count())->toBe(0);
});
