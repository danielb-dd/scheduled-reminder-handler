<?php

/**
 * Tier 1 (pure unit) test bootstrap.
 *
 * Deliberately does NOT bootstrap CiviCRM or a database — these tests exercise
 * only dependency-free logic, so they run anywhere (CI, local, SiteGround SSH)
 * in seconds. Integration of the DB-backed pieces (retry SQL, the RetryFailed-
 * Reminders job) belongs in a separate headless (HeadlessInterface) tier.
 *
 * We load:
 *   - Composer autoload (PHPUnit and its deps).
 *   - CRM_Schedulereminderhandler_StalenessGuard (the pure decision class).
 *   - The extension main file, for its hook FUNCTIONS (e.g. alterMailer). Its
 *     civix include only defines functions + the ExtensionUtil class with no
 *     CiviCRM calls at include time, so this is safe with no CiviCRM present.
 */

declare(strict_types=1);

// Deterministic date math regardless of host timezone.
date_default_timezone_set('UTC');

$extRoot = dirname(__DIR__, 2);

require_once $extRoot . '/vendor/autoload.php';
require_once $extRoot . '/CRM/Schedulereminderhandler/StalenessGuard.php';
require_once $extRoot . '/schedulereminderhandler.php';
