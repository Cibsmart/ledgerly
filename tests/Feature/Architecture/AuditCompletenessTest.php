<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Support\Facades\File;

/**
 * Guardrail: every state-mutating action must leave an audit trail via RecordAudit.
 *
 * Accounting invariant 5 ("everything is attributable") is only as good as its weakest
 * write path. New actions therefore default to "must audit" — opting out is a deliberate,
 * reviewable edit to the $exemptions array below, and each entry carries a one-line reason.
 *
 * Implementation note: this is a reflection-based per-class check rather than Pest's
 * arch()->toUse(). The arch expectation treats 'App\Actions' as a single layer and only
 * asserts that the layer *collectively* references RecordAudit — it passes as long as any
 * one action audits, so it has no per-class teeth. Iterating the classes ourselves is the
 * only way to fail when a single state-mutating action skips its audit trail.
 *
 * Known limitation: this is scoped to the Action layer. Audits recorded elsewhere (a
 * controller, say) are not policed here, and neither is the quality of the audit context.
 */
test('state-mutating actions record an audit entry', function () {
    /** @var array<int, class-string> $exemptions */
    $exemptions = [
        // Framework-driven auth: Fortify owns these lifecycles.
        CreateNewUser::class,
        ResetUserPassword::class,
    ];

    $actionsPath = app_path('Actions');

    expect(File::isDirectory($actionsPath))->toBeTrue('app/Actions is missing.');

    $offenders = collect(File::allFiles($actionsPath))
        ->filter(fn ($file) => $file->getExtension() === 'php')
        ->map(function ($file): string {
            $relative = str($file->getRelativePathname())->replace(['/', '.php'], ['\\', ''])->value();

            return 'App\\Actions\\'.$relative;
        })
        ->reject(fn (string $class) => in_array($class, $exemptions, true))
        ->reject(function (string $class): bool {
            $path = (new ReflectionClass($class))->getFileName();

            return $path !== false && str_contains((string) file_get_contents($path), 'RecordAudit');
        })
        ->values()
        ->all();

    expect($offenders)->toBe([], 'These state-mutating actions must record an audit via RecordAudit '
        .'(or be added to the exemption list with a reason): '.implode(', ', $offenders));
});
