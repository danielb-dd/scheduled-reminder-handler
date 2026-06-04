<?php

/**
 * Finds civicrm_action_log rows marked is_error=1 with "Failed to send message"
 * (SMTP/transport failures, not data errors) and resets them so the next cron
 * run will retry them.
 *
 * Only rows whose failure occurred within max_age_days are reset — older
 * failures are left alone to avoid resending genuinely stale emails.
 */
class CRM_Schedulereminderhandler_Job_RetryFailedReminders {

  const SMTP_ERROR_MESSAGE = 'Failed to send message';

  public static function run(array $params): array {
    $maxAgeDays = max(1, (int)($params['max_age_days'] ?? 3));

    // Find eligible rows: SMTP failure, active schedule, failed recently.
    $dao = CRM_Core_DAO::executeQuery("
      SELECT al.id, al.action_schedule_id, al.action_date_time
      FROM civicrm_action_log al
      JOIN civicrm_action_schedule s ON s.id = al.action_schedule_id
      WHERE al.is_error   = 1
        AND al.message    = %1
        AND s.is_active   = 1
        AND al.action_date_time >= DATE_SUB(NOW(), INTERVAL %2 DAY)
    ", [
      1 => [self::SMTP_ERROR_MESSAGE, 'String'],
      2 => [$maxAgeDays, 'Integer'],
    ]);

    $ids = [];
    while ($dao->fetch()) {
      $ids[] = (int) $dao->id;
    }

    if (empty($ids)) {
      CRM_Core_Error::debug_log_message(
        "RetryFailedReminders: no eligible rows (max_age_days={$maxAgeDays})"
      );
      return civicrm_api3_create_success(
        ['reset_count' => 0],
        $params,
        'Job',
        'RetryFailedReminders'
      );
    }

    $idList = implode(',', $ids);
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_action_log
      SET action_date_time = NULL,
          is_error         = 0
      WHERE id IN ({$idList})
    ");

    $count = count($ids);
    CRM_Core_Error::debug_log_message(
      "RetryFailedReminders: reset {$count} rows for retry (max_age_days={$maxAgeDays})"
    );

    return civicrm_api3_create_success(
      ['reset_count' => $count],
      $params,
      'Job',
      'RetryFailedReminders'
    );
  }

}
