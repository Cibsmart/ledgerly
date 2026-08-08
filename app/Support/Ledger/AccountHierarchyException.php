<?php

namespace App\Support\Ledger;

/**
 * A change to the chart's shape that would strand posted history.
 *
 * Lives here rather than beside the actions that raise it so that
 * tests/Feature/Architecture/AuditCompletenessTest.php keeps its teeth: that
 * guardrail requires every class under app/Actions to record an audit, and an
 * exception class placed there would have to be exempted. Exemptions are meant
 * to be rare and deliberate, so the exception moves rather than the rule.
 */
final class AccountHierarchyException extends LedgerException {}
