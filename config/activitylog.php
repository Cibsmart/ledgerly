<?php

use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Models\Activity;

return [

    /*
     * If set to false, no activities will be saved to the database.
     *
     * Leave this on. Accounting invariant 5 makes attribution part of what a
     * posting *is*, not a diagnostic that can be turned down in production.
     * App\Actions\Audit\RecordAudit throws rather than returning quietly when
     * this is false, so disabling it fails the ledger loudly instead of
     * producing unattributable entries.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * When the clean command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     *
     * The package default is 365 days, which is wrong for a ledger: this is the
     * audit trail behind filed accounts, and it has to outlive any period a tax
     * authority might reopen. `activitylog:clean` is deliberately not scheduled
     * anywhere in this application — the value below only bounds the damage if
     * someone runs it by hand.
     */
    'clean_after_days' => 3650,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject relationship on activities
     * will include soft deleted models.
     */
    'include_soft_deleted_subjects' => false,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     */
    'activity_model' => Activity::class,

    /*
     * These attributes will be excluded from logging for all models.
     * Model-specific exclusions via logExcept() are merged with these.
     */
    'default_except_attributes' => [],

    /*
     * When enabled, activities are buffered in memory and inserted in a
     * single bulk query after the response has been sent to the client.
     *
     * This must stay off. Buffering moves the audit write outside the
     * transaction that posted the entry, so a rolled-back posting could still
     * leave an audit row, and a failed flush would drop the audit for a
     * committed one. Neither is survivable in a ledger, and the volume that
     * would justify buffering does not exist here.
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    /*
     * These action classes can be overridden to customize how activities
     * are logged and cleaned. Your custom classes must extend the originals.
     */
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
