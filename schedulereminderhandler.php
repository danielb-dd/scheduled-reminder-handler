<?php

require_once 'schedulereminderhandler.civix.php';

use CRM_Schedulereminderhandler_ExtensionUtil as E;

define("NUMBER_OF_DAY_ALLOWED_FOR_OLD_EMAILS", 2);

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function schedulereminderhandler_civicrm_config(&$config): void {
  _schedulereminderhandler_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function schedulereminderhandler_civicrm_install(): void {
  _schedulereminderhandler_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function schedulereminderhandler_civicrm_enable(): void {
  _schedulereminderhandler_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_alterMailer().
 *
 * Root-cause fix for "421 too many messages per connection".
 *
 * CiviCRM core opens ONE persistent SMTP connection per process
 * (CRM_Utils_Mail::createMailer sets persist=TRUE) and reuses it for every
 * send in that run, including scheduled reminders. Mailtrap caps a single
 * connection at 100 messages, so a reminder going to 100+ recipients gets cut
 * off (~100 sent) with: 421 4.7.0 Error: too many messages per connection.
 *
 * Setting persist=FALSE makes PEAR Mail_smtp disconnect after every send, so
 * each message gets a fresh connection and the per-connection cap is never
 * reached. This prevents the failure outright; the alterMailParams retry/
 * staleness logic below remains as a safety net for genuinely transient
 * transport errors (brief rate limits, momentary outages).
 *
 * We mutate the existing mailer object rather than reassign it — the inner
 * PEAR Mail_smtp reads $persist at the end of each send(), so flipping it on
 * the live object takes effect per-message. (FilteredPearMailer::__set
 * forwards the assignment to the wrapped driver.)
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterMailer/
 */
function schedulereminderhandler_civicrm_alterMailer(&$mailer, $driver, $params) {
  if ($driver === 'smtp' && is_object($mailer)) {
    $mailer->persist = FALSE;
  }
}

/**
 * Implements hook_civicrm_alterMailParams().
 *
 * Stops stale scheduled reminder emails from being sent (e.g. during retry).
 * No business logic changed from original — only added absolute_date support
 * (bug fix: previously those schedules were always silently aborted).
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterMailParams
 */
function schedulereminderhandler_civicrm_alterMailParams(&$params, $context) {
  if (empty($params['entity'])
    || $params['entity'] !== 'action_schedule'
    || $params['groupName'] !== 'Scheduled Reminder Sender'
  ) {
    return;
  }

  // Register a PHP shutdown handler the first time we see a scheduled reminder
  // send in this request. At process end it resets any SMTP failures from the
  // last 10 minutes so they are immediately queued for the next cron.
  static $shutdownRegistered = FALSE;
  if (!$shutdownRegistered) {
    register_shutdown_function('schedulereminderhandler_reset_smtp_failures_on_shutdown');
    $shutdownRegistered = TRUE;
  }

  $actionScheduleId   = $params['entity_id'];
  $actionSearchResult = $params['actionSearchResult'];
  $now                = CRM_Utils_Time::getTime();
  $noOfDaysAllowed    = NUMBER_OF_DAY_ALLOWED_FOR_OLD_EMAILS;

  // Get action schedule details
  $actionSchedule = CRM_Core_DAO::executeQuery(
    "SELECT * FROM civicrm_action_schedule WHERE id = %1",
    [1 => [$actionScheduleId, 'Integer']]
  );
  $actionSchedule->fetch();

  // Decide whether this send is stale. The decision logic is pure and lives in
  // CRM_Schedulereminderhandler_StalenessGuard (unit-tested); this hook only
  // gathers the inputs and delegates. Schedules we can't evaluate (neither
  // start_action_date nor absolute_date) default to allow — the guard only
  // acts on confirmed staleness.
  $abortEmail = FALSE;
  $nowYmdHis  = (int) $now;

  // ── Relative schedule (N units before/after an entity date) ──────────────
  if ($actionSchedule->start_action_date) {
    $startActionDateColumn = $actionSchedule->start_action_date;
    $startActionDate       = $actionSearchResult->$startActionDateColumn;

    $abortEmail = CRM_Schedulereminderhandler_StalenessGuard::shouldAbortRelative(
      (string) $startActionDate,
      (string) $actionSchedule->start_action_condition,
      (int) $actionSchedule->start_action_offset,
      (string) $actionSchedule->start_action_unit,
      $nowYmdHis,
      $noOfDaysAllowed
    );

    CRM_Core_Error::debug_log_message(
      "ScheduleReminderHandler schedule {$actionScheduleId} (relative):"
      . " entityDate={$startActionDate}, now={$now}, abort=" . ($abortEmail ? 'Y' : 'N')
    );
  }

  // ── Fixed absolute_date schedule ──────────────────────────────────────────
  elseif ($actionSchedule->absolute_date) {
    $abortEmail = CRM_Schedulereminderhandler_StalenessGuard::shouldAbortAbsolute(
      (string) $actionSchedule->absolute_date,
      $nowYmdHis,
      $noOfDaysAllowed
    );

    CRM_Core_Error::debug_log_message(
      "ScheduleReminderHandler schedule {$actionScheduleId} (absolute):"
      . " scheduleDate={$actionSchedule->absolute_date}, now={$now}, abort=" . ($abortEmail ? 'Y' : 'N')
    );
  }

  if ($abortEmail) {
    $params['abortMailSend'] = TRUE;
  }
}

/**
 * WordPress shutdown callback — resets SMTP failures from the last 10 minutes.
 *
 * Registered by alterMailParams the first time a scheduled reminder send is
 * detected in the current request. Fires at process end (after all emails in
 * this cron run are processed), so failures are immediately queued for retry
 * on the next cron tick — no separate retry cron delay needed.
 *
 * The 10-minute window is intentionally tight: it covers only failures that
 * happened in this request, avoiding accidental reset of older stuck rows
 * (those are handled by the hourly RetryFailedReminders job as a fallback).
 */
function schedulereminderhandler_reset_smtp_failures_on_shutdown(): void {
  try {
    $dao = CRM_Core_DAO::executeQuery("
      SELECT al.id
      FROM civicrm_action_log al
      JOIN civicrm_action_schedule s ON s.id = al.action_schedule_id
      WHERE al.is_error   = 1
        AND al.message    = 'Failed to send message'
        AND s.is_active   = 1
        AND al.action_date_time >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");

    $ids = [];
    while ($dao->fetch()) {
      $ids[] = (int) $dao->id;
    }

    if (empty($ids)) {
      return;
    }

    $idList = implode(',', $ids);
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_action_log
      SET action_date_time = NULL,
          is_error         = 0
      WHERE id IN ({$idList})
    ");

    CRM_Core_Error::debug_log_message(
      'ScheduleReminderHandler shutdown: reset ' . count($ids) . ' SMTP failure(s) — will retry on next cron'
    );
  }
  catch (Exception $e) {
    // Shutdown context — log and swallow so we don't cause a fatal at process end
    CRM_Core_Error::debug_log_message(
      'ScheduleReminderHandler shutdown error: ' . $e->getMessage()
    );
  }
}