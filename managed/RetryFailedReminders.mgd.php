<?php

/**
 * Registers the RetryFailedReminders job in civicrm_job so it appears under
 * Administer → Scheduled Jobs and can be enabled/configured there.
 *
 * Disabled by default — enable once you are satisfied with the alterMailParams
 * staleness guard and want automatic retry to run on a schedule.
 */
return [
  [
    'name'   => 'Job_RetryFailedReminders',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version'       => 3,
      'name'          => 'Retry Failed Scheduled Reminders',
      'description'   => 'Finds "Failed to send message" errors in civicrm_action_log for active schedules and resets them so CiviCRM retries on the next cron run. Only resets failures within max_age_days (default 3).',
      'run_frequency' => 'Hourly',
      'api_entity'    => 'Job',
      'api_action'    => 'retry_failed_reminders',
      'parameters'    => 'max_age_days=3',
      'is_active'     => 0,
    ],
  ],
];
