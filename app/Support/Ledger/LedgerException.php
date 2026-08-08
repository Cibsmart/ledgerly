<?php

namespace App\Support\Ledger;

use RuntimeException;

/**
 * Base class for the ledger's own refusals.
 *
 * These live under Support rather than in an app/Exceptions folder because the
 * application's directory structure is fixed (see CLAUDE.md) and these belong
 * beside the value objects and the write path they guard.
 *
 * Each subclass exists so a caller — a controller rendering a validation
 * error, a test asserting a rejection — can distinguish "this does not balance"
 * from "that period is closed" without matching on message text.
 */
abstract class LedgerException extends RuntimeException {}
