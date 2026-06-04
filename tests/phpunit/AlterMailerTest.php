<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the alterMailer persist fix.
 *
 * The hook must set persist=FALSE on the SMTP mailer (so CiviCRM reconnects per
 * message and never hits Mailtrap's 100-messages-per-connection cap), and must
 * leave non-SMTP drivers untouched. We pass a tiny stub mailer with a public
 * $persist property — exactly what FilteredPearMailer::__set forwards to in
 * production — and assert the resulting value.
 *
 * @covers ::schedulereminderhandler_civicrm_alterMailer
 */
final class AlterMailerTest extends TestCase {

  private function stubMailer(): object {
    return new class {
      public $persist = TRUE;
    };
  }

  public function testSmtpMailerIsMadeNonPersistent(): void {
    $mailer = $this->stubMailer();
    schedulereminderhandler_civicrm_alterMailer($mailer, 'smtp', []);
    $this->assertFalse($mailer->persist, 'smtp driver → persist must be disabled');
  }

  /**
   * @dataProvider nonSmtpDriverProvider
   */
  public function testNonSmtpMailerIsUntouched(string $driver): void {
    $mailer = $this->stubMailer();
    schedulereminderhandler_civicrm_alterMailer($mailer, $driver, []);
    $this->assertTrue($mailer->persist, "{$driver} driver → persist must be left untouched");
  }

  public function nonSmtpDriverProvider(): array {
    return [
      'sendmail' => ['sendmail'],
      'mail'     => ['mail'],
      'mock'     => ['mock'],
    ];
  }

  public function testNonObjectMailerDoesNotError(): void {
    $mailer = NULL;
    schedulereminderhandler_civicrm_alterMailer($mailer, 'smtp', []);
    $this->assertNull($mailer, 'non-object mailer → guarded, no error');
  }

}
