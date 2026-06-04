<?php

/**
 * Job.RetryFailedReminders API
 *
 * Resets "Failed to send message" errors in civicrm_action_log so CiviCRM
 * retries sending on the next scheduled reminder cron run.
 *
 * Usage (CLI):
 *   cv api Job.retry_failed_reminders max_age_days=3
 */
function civicrm_api3_job_retry_failed_reminders(array $params): array {
  return CRM_Schedulereminderhandler_Job_RetryFailedReminders::run($params);
}

function _civicrm_api3_job_retry_failed_reminders_spec(array &$spec): void {
  $spec['max_age_days'] = [
    'title'       => 'Maximum Age in Days',
    'description' => 'Only retry failures that occurred within the last N days. Default: 3.',
    'type'        => CRM_Utils_Type::T_INT,
    'api.default' => 3,
  ];
}
