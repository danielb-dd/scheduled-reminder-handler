<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure staleness decision.
 *
 * Fixed "now" = 2026-06-04 10:00:00 (20260604100000), retry window = 2 days.
 * No CiviCRM, no database — pure logic only.
 *
 * @covers CRM_Schedulereminderhandler_StalenessGuard
 */
final class StalenessGuardTest extends TestCase {

  private const NOW    = 20260604100000; // 2026-06-04 10:00:00
  private const WINDOW = 2;

  /**
   * @dataProvider relativeProvider
   */
  public function testRelative(string $entityDate, string $condition, int $offset, string $unit, bool $expectAbort, string $label): void {
    $this->assertSame(
      $expectAbort,
      CRM_Schedulereminderhandler_StalenessGuard::shouldAbortRelative(
        $entityDate, $condition, $offset, $unit, self::NOW, self::WINDOW
      ),
      $label
    );
  }

  public function relativeProvider(): array {
    // [entityDate, condition, offset, unit, expectAbort, label]
    return [
      // On-time sends — intended send date is today → always ALLOW.
      'before, on time'              => ['2026-06-05 10:00:00', 'before', 1, 'day',   FALSE, 'intended=today → allow'],
      'after, on time'               => ['2026-06-03 10:00:00', 'after',  1, 'day',   FALSE, 'intended=today → allow'],
      'after 1 month, on time'       => ['2026-05-04 10:00:00', 'after',  1, 'month', FALSE, 'long offset, intended=today → allow'],
      'before, future intended'      => ['2026-06-10 10:00:00', 'before', 1, 'day',   FALSE, 'intended in future → allow'],

      // Retries — intended send date in the past.
      'before, retry within window'  => ['2026-06-04 10:00:00', 'before', 1, 'day',   FALSE, 'intended=yesterday, now<max → allow'],
      'after, retry within window'   => ['2026-06-02 10:00:00', 'after',  1, 'day',   FALSE, 'intended=yesterday, now<max → allow'],

      // Stale — intended send date well outside the 2-day window → ABORT.
      'before, stale 10 days'        => ['2026-05-26 10:00:00', 'before', 1, 'day',   TRUE,  'intended=2026-05-25, far past → abort'],
      'after, stale'                 => ['2026-05-20 10:00:00', 'after',  1, 'day',   TRUE,  'intended=2026-05-21, far past → abort'],
    ];
  }

  /**
   * @dataProvider absoluteProvider
   */
  public function testAbsolute(string $absoluteDate, bool $expectAbort, string $label): void {
    $this->assertSame(
      $expectAbort,
      CRM_Schedulereminderhandler_StalenessGuard::shouldAbortAbsolute(
        $absoluteDate, self::NOW, self::WINDOW
      ),
      $label
    );
  }

  public function absoluteProvider(): array {
    return [
      'absolute today'         => ['2026-06-04', FALSE, 'date=today → allow'],
      'absolute future'        => ['2026-06-10', FALSE, 'date in future → allow'],
      'absolute within window' => ['2026-06-03', FALSE, 'date=yesterday, now<max → allow'],
      'absolute stale'         => ['2026-05-01', TRUE,  'date well past → abort'],
    ];
  }

  /**
   * Documents a KNOWN boundary quirk: for absolute_date schedules the retry
   * window ends at MIDNIGHT of (date + windowDays), because absolute_date has
   * no time component. So a send whose absolute date was exactly `windowDays`
   * ago is already considered stale at any time after midnight today.
   *
   * now=2026-06-04 10:00, window=2 → absolute 2026-06-02 has maxDate
   * 2026-06-04 00:00:00, and 10:00 > 00:00 → ABORT.
   *
   * This test pins the CURRENT behavior. If the boundary is later widened to
   * end-of-day, flip the expectation to FALSE here.
   */
  public function testAbsoluteBoundaryIsMidnight(): void {
    $this->assertTrue(
      CRM_Schedulereminderhandler_StalenessGuard::shouldAbortAbsolute('2026-06-02', self::NOW, self::WINDOW),
      'absolute date exactly windowDays ago → stale after midnight (known midnight-boundary behavior)'
    );
  }

  /**
   * The window scales with the windowDays argument.
   */
  public function testWiderWindowAllowsOlderRetry(): void {
    // Intended send 2026-06-01 (3 days before now). Stale at window=2, allowed at window=5.
    $this->assertTrue(
      CRM_Schedulereminderhandler_StalenessGuard::shouldAbortAbsolute('2026-06-01', self::NOW, 2),
      'window=2 → 3-day-old retry is stale'
    );
    $this->assertFalse(
      CRM_Schedulereminderhandler_StalenessGuard::shouldAbortAbsolute('2026-06-01', self::NOW, 5),
      'window=5 → 3-day-old retry still allowed'
    );
  }

}
