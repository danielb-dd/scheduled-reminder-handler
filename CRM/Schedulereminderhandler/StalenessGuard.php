<?php

/**
 * Pure, dependency-free staleness decision for scheduled reminder emails.
 *
 * No database access, no CiviCRM services, no current-time globals — every
 * input is passed in. This makes the decision fully unit-testable without a
 * CiviCRM bootstrap (see tests/phpunit/CRM/Schedulereminderhandler/StalenessGuardTest.php).
 *
 * The alterMailParams hook in schedulereminderhandler.php is a thin adapter
 * that gathers these inputs from CiviCRM (the action_schedule row, the entity
 * date, the current time) and delegates the actual decision here.
 *
 * Decision rule (same for relative and absolute schedules):
 *   - intended send date is today or in the future → ALLOW (never stale).
 *     CiviCRM only creates the send row once timing is met, so a row processed
 *     today is by definition an on-time send.
 *   - intended send date is in the past            → this is a retry; ALLOW
 *     only while now <= intendedSend + windowDays, otherwise ABORT (stale).
 */
class CRM_Schedulereminderhandler_StalenessGuard {

  /**
   * Decide whether a RELATIVE schedule's reminder is stale and should abort.
   *
   * @param string $entityDate The date the schedule is relative to (e.g. event
   *   start), in any strtotime-parseable form.
   * @param string $condition 'before' or 'after'.
   * @param int $offset The start_action_offset (number of units).
   * @param string $unit The start_action_unit ('day', 'week', 'month', 'hour', ...).
   * @param int $nowYmdHis Current time as a YmdHis integer (e.g. 20260604100000).
   * @param int $windowDays Retry window in days.
   * @return bool TRUE = abort (stale); FALSE = allow.
   */
  public static function shouldAbortRelative(string $entityDate, string $condition, int $offset, string $unit, int $nowYmdHis, int $windowDays): bool {
    $entityTs       = strtotime($entityDate);
    $sign           = ($condition === 'before') ? '-' : '+';
    $intendedSendTs = strtotime(date('YmdHis', $entityTs) . " {$sign}{$offset} {$unit}");
    return self::decide($intendedSendTs, $nowYmdHis, $windowDays);
  }

  /**
   * Decide whether an ABSOLUTE_DATE schedule's reminder is stale and should abort.
   *
   * @param string $absoluteDate The fixed send date, e.g. '2026-05-01'.
   * @param int $nowYmdHis Current time as a YmdHis integer.
   * @param int $windowDays Retry window in days.
   * @return bool TRUE = abort (stale); FALSE = allow.
   */
  public static function shouldAbortAbsolute(string $absoluteDate, int $nowYmdHis, int $windowDays): bool {
    return self::decide(strtotime($absoluteDate), $nowYmdHis, $windowDays);
  }

  /**
   * Shared staleness decision given the intended-send timestamp.
   *
   * @param int $intendedSendTs Unix timestamp of the intended send.
   * @param int $nowYmdHis Current time as a YmdHis integer.
   * @param int $windowDays Retry window in days.
   * @return bool TRUE = abort (stale); FALSE = allow.
   */
  private static function decide(int $intendedSendTs, int $nowYmdHis, int $windowDays): bool {
    $todayYmd    = (int) substr((string) $nowYmdHis, 0, 8);
    $intendedYmd = (int) date('Ymd', $intendedSendTs);

    // Today or future → on-time send, never stale.
    if ($intendedYmd >= $todayYmd) {
      return FALSE;
    }

    // Past → retry. Stale once now is beyond the N-day window.
    $maxActionDate = (int) date('YmdHis', strtotime("+{$windowDays} day", $intendedSendTs));
    return $nowYmdHis > $maxActionDate;
  }

}
