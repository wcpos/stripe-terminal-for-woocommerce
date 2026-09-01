<?php
/**
 * Tests for the Update POT workflow.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

namespace WCPOS\WooCommercePOS\StripeTerminal\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests the workflow inputs and catalog comparison script.
 */
class UpdatePotWorkflowTest extends TestCase {

	private const WORKFLOW = STWC_PLUGIN_DIR . '.github/workflows/update-pot.yml';

	/**
	 * GitHub Actions inputs cannot be executed by PHPUnit, so these assertions
	 * pin the serialized tool and make-pot configuration consumed by the action.
	 */
	public function test_workflow_pins_wp_cli_for_reproducible_generation(): void {
		$workflow = file_get_contents( self::WORKFLOW );

		$this->assertIsString( $workflow );
		$this->assertStringContainsString( 'tools: wp-cli:2.12.0', $workflow );
	}

	/**
	 * Ensures generated and duplicate sources are excluded.
	 */
	public function test_workflow_excludes_noncanonical_translation_sources(): void {
		$workflow = file_get_contents( self::WORKFLOW );

		$this->assertIsString( $workflow );
		$this->assertStringContainsString(
			'--exclude=includes/abstracts,includes/utils,assets/js/blocks',
			$workflow
		);
	}

	/**
	 * Main only accepts changes through pull requests (repository ruleset), so
	 * the workflow must propose the regenerated catalog as a PR; a direct push
	 * from the runner is rejected with GH013 and fails the run.
	 */
	public function test_workflow_opens_a_pull_request_instead_of_pushing_to_main(): void {
		$workflow = file_get_contents( self::WORKFLOW );

		$this->assertIsString( $workflow );
		$this->assertStringContainsString( 'uses: peter-evans/create-pull-request@', $workflow );
		$this->assertStringContainsString( 'pull-requests: write', $workflow );
		$this->assertStringNotContainsString( 'git-auto-commit-action', $workflow );
	}

	/**
	 * Ensures only the volatile creation date is ignored.
	 */
	public function test_pot_comparison_ignores_only_the_creation_date(): void {
		$script = $this->get_check_for_changes_script();
		$old    = $this->pot_fixture( 'Old package', 'https://example.com/old', '2026-08-07T01:00:00+00:00' );

		$date_only = $this->pot_fixture( 'Old package', 'https://example.com/old', '2026-08-07T02:00:00+00:00' );
		$this->assertSame( '', $this->run_check_for_changes( $script, $old, $date_only ) );

		$metadata_change = $this->pot_fixture( 'New package', 'https://example.com/new', '2026-08-07T02:00:00+00:00' );
		$this->assertSame( "changes=true\n", $this->run_check_for_changes( $script, $old, $metadata_change ) );
	}

	/**
	 * Extracts the catalog comparison script from the workflow.
	 */
	private function get_check_for_changes_script(): string {
		$lines  = file( self::WORKFLOW, FILE_IGNORE_NEW_LINES );
		$script = array();
		$in_run = false;

		$this->assertIsArray( $lines );

		foreach ( $lines as $line ) {
			if ( '        run: |' === $line ) {
				$in_run = true;
				continue;
			}

			if ( ! $in_run ) {
				continue;
			}

			if ( '' === $line ) {
				$script[] = '';
				continue;
			}

			if ( 0 !== strpos( $line, '          ' ) ) {
				break;
			}

			$script[] = substr( $line, 10 );
		}

		$this->assertNotEmpty( $script );

		return implode( "\n", $script );
	}

	/**
	 * Runs the catalog comparison script against a temporary repository.
	 *
	 * @param string $script  Shell script extracted from the workflow.
	 * @param string $old_pot Catalog committed at HEAD.
	 * @param string $new_pot Newly generated catalog.
	 */
	private function run_check_for_changes( string $script, string $old_pot, string $new_pot ): string {
		$directory = sys_get_temp_dir() . '/stwc-update-pot-' . bin2hex( random_bytes( 8 ) );
		$languages = $directory . '/languages';
		$output    = $directory . '/github-output';

		mkdir( $languages, 0777, true );
		file_put_contents( $languages . '/stripe-terminal-for-woocommerce.pot', $old_pot );

		$this->run_command( 'git init --quiet', $directory );
		$this->run_command( 'git add languages/stripe-terminal-for-woocommerce.pot', $directory );
		$this->run_command(
			'git -c user.name=Test -c user.email=test@example.com commit --quiet -m fixture',
			$directory
		);

		file_put_contents( $languages . '/stripe-terminal-for-woocommerce.pot', $new_pot );
		file_put_contents( $output, '' );

		$this->run_command(
			'GITHUB_OUTPUT=' . escapeshellarg( $output ) . ' bash -euo pipefail -c ' . escapeshellarg( $script ),
			$directory
		);

		$result = file_get_contents( $output );
		$this->remove_directory( $directory );

		return (string) $result;
	}

	/**
	 * Runs a command in the temporary repository.
	 *
	 * @param string $command   Command to run.
	 * @param string $directory Working directory for the command.
	 */
	private function run_command( string $command, string $directory ): void {
		exec( 'cd ' . escapeshellarg( $directory ) . ' && ' . $command . ' 2>&1', $output, $exit_code );

		$this->assertSame( 0, $exit_code, implode( "\n", $output ) );
	}

	/**
	 * Removes the temporary repository.
	 *
	 * @param string $directory Directory to remove.
	 */
	private function remove_directory( string $directory ): void {
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}

		rmdir( $directory );
	}

	/**
	 * Builds a small POT catalog fixture.
	 *
	 * @param string $package       Package header value.
	 * @param string $bugs_url      Report-Msgid-Bugs-To header value.
	 * @param string $creation_date POT-Creation-Date header value.
	 */
	private function pot_fixture( string $package, string $bugs_url, string $creation_date ): string {
		return <<<POT
# Copyright
msgid ""
msgstr ""
"Project-Id-Version: {$package}\\n"
"Report-Msgid-Bugs-To: {$bugs_url}\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"POT-Creation-Date: {$creation_date}\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"X-Generator: WP-CLI 2.12.0\\n"
"X-Domain: stripe-terminal-for-woocommerce\\n"

msgid "Stripe Terminal"
msgstr ""
POT;
	}
}
