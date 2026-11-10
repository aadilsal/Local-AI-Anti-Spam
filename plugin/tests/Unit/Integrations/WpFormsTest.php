<?php
/**
 * WPForms field mapping.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Tests\Unit\Integrations;

use LocalAIAntiSpam\Integrations\WpForms;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class WpFormsTest extends TestCase {

	public function test_map_reads_formatted_fields_and_drops_secrets() {
		$mapped = WpForms::map(
			array(
				0 => array(
					'name'  => 'Name',
					'value' => 'Sarah Jones',
					'id'    => 0,
					'type'  => 'name',
				),
				1 => array(
					'name'  => 'Email',
					'value' => 'sarah@example.com',
					'id'    => 1,
					'type'  => 'email',
				),
				2 => array(
					'name'  => 'Comment or Message',
					'value' => 'Can I book a table for four on Friday?',
					'id'    => 2,
					'type'  => 'textarea',
				),
				3 => array(
					'name'  => 'Password',
					'value' => 'hunter2',
					'id'    => 3,
					'type'  => 'password',
				),
				4 => array(
					'name'  => 'Card',
					'value' => '4111',
					'id'    => 4,
					'type'  => 'credit-card',
				),
				5 => array(
					'name'  => 'Total',
					'value' => '$10',
					'id'    => 5,
					'type'  => 'payment-total',
				),
			)
		);
		$this->assertSame( 'Can I book a table for four on Friday?', $mapped['text'] );
		$this->assertSame( 'sarah@example.com', $mapped['email'] );
		$this->assertSame( 'Sarah Jones', $mapped['name'] );
		$this->assertSame( array( 0, 1, 2 ), array_keys( $mapped['kept'] ) );
		$this->assertArrayNotHasKey( 'Password', $mapped['stored'] );
	}

	public function test_duplicate_labels_stay_distinct() {
		$mapped = WpForms::map(
			array(
				7 => array(
					'name'  => 'Details',
					'value' => 'First part',
					'id'    => 7,
					'type'  => 'textarea',
				),
				8 => array(
					'name'  => 'Details',
					'value' => 'Second part',
					'id'    => 8,
					'type'  => 'textarea',
				),
			)
		);
		$this->assertSame( "First part\nSecond part", $mapped['text'] );
		$this->assertSame( array( 'Details', 'Details #8' ), array_keys( $mapped['stored'] ) );
	}
}
