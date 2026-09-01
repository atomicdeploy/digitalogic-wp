<?php
/**
 * Panel currency request-path source tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Protect exact currency receipts and bounded browser state. */
final class PanelCurrencyAsyncSourceTest extends TestCase {

	/** Currency writes must use fresh Ajax and classify delivery failures exactly. */
	public function test_currency_save_uses_fresh_ajax_and_exact_failure_state(): void {
		$source = $this->read_source( dirname( __DIR__ ) . '/assets/js/panel-app.js' );
		$match  = array();

		$this->assertSame(
			1,
			preg_match( '/saveCurrency:\s*function[\s\S]*?\n\s*},\n\s*watchCurrencyJob:/', $source, $match )
		);
		$this->assertStringContainsString( 'ajaxOnly: true', $match[0] );
		$this->assertStringContainsString( 'noAutoReplay: true', $match[0] );
		$this->assertStringContainsString( 'error.outcome_unknown || error.deliveryUnknown', $match[0] );
		$this->assertStringContainsString( 'if (!outcomeUnknown)', $match[0] );
		$this->assertStringContainsString( 'self.currencyJob = null', $match[0] );
	}

	/** Jobless recovery guidance must not masquerade as zero-percent progress. */
	public function test_jobless_recovery_hides_progress_percentage(): void {
		$view = $this->read_source( dirname( __DIR__ ) . '/includes/panel/views/app.php' );

		$this->assertStringContainsString(
			'v-if="currencyJob.job_id && currencyJob.generation"',
			$view
		);
	}

	/**
	 * Read one repository source file without involving the network layer.
	 *
	 * @param string $path Absolute source path.
	 * @return string Source contents.
	 */
	private function read_source( string $path ): string {
		$file    = new SplFileObject( $path );
		$content = '';

		while ( ! $file->eof() ) {
			$content .= $file->fgets();
		}

		return $content;
	}
}
