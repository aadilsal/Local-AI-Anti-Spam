<?php
/**
 * CF7 field mapping and mail rebuilding.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Tests\Unit\Integrations;

use LocalAIAntiSpam\Integrations\Cf7Fields;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class Cf7FieldsTest extends TestCase {

	const TYPES = array(
		'your-name'     => 'text',
		'your-email'    => 'email',
		'your-subject'  => 'text',
		'your-message'  => 'textarea',
		'your-phone'    => 'tel',
		'services'      => 'checkbox',
		'card-number'   => 'text',
		'secret-answer' => 'quiz',
		'cv'            => 'file',
	);

	const POSTED = array(
		'your-name'     => 'Sarah Jones',
		'your-email'    => 'sarah@example.com',
		'your-subject'  => 'Table booking',
		'your-message'  => "Hi, can I book a table for four?\nThanks",
		'your-phone'    => '020 7946 0958',
		'services'      => array( 'Dinner', 'Drinks' ),
		'card-number'   => '4111 1111 1111 1111',
		'secret-answer' => '4',
		'cv'            => 'cv.pdf',
	);

	public function test_map_picks_message_email_and_name_and_drops_secrets() {
		$mapped = Cf7Fields::map( self::TYPES, self::POSTED );
		$this->assertSame( "Table booking\nHi, can I book a table for four?\nThanks", $mapped['text'] );
		$this->assertSame( 'sarah@example.com', $mapped['email'] );
		$this->assertSame( 'Sarah Jones', $mapped['name'] );
		$this->assertArrayNotHasKey( 'card-number', $mapped['stored'] );
		$this->assertArrayNotHasKey( 'secret-answer', $mapped['stored'] );
		$this->assertSame( array( 'Dinner', 'Drinks' ), $mapped['stored']['services'] );
		$this->assertSame( 'cv.pdf', $mapped['stored']['cv'] );
	}

	public function test_map_without_a_message_box_uses_other_text_fields() {
		$mapped = Cf7Fields::map(
			array(
				'company' => 'text',
				'website' => 'url',
				'email'   => 'email',
			),
			array(
				'company' => 'Acme SEO',
				'website' => 'https://seo.example',
				'email'   => 'x@seo.example',
			)
		);
		$this->assertSame( "Acme SEO\nhttps://seo.example", $mapped['text'] );
	}

	public function test_compose_rebuilds_cf7_default_mail() {
		$template = array(
			'recipient'          => '[_site_admin_email]',
			'sender'             => '[_site_title] <wordpress@example.com>',
			'subject'            => '[_site_title] "[your-subject]"',
			'body'               => "From: [your-name] [your-email]\nSubject: [your-subject]\n\nMessage Body:\n[your-message]\n\nServices: [services]\nUnknown: [not-a-field]\n\n-- \nSent from [_site_title] ([_site_url]) at [_date]",
			'additional_headers' => 'Reply-To: [your-email]',
			'use_html'           => false,
		);
		$special  = array(
			'_site_admin_email' => 'owner@example.com',
			'_site_title'       => 'Bistro',
			'_site_url'         => 'https://bistro.example',
			'_date'             => '10 November 2026',
		);
		$mail     = Cf7Fields::compose( $template, Cf7Fields::map( self::TYPES, self::POSTED )['stored'], $special );

		$this->assertSame( 'owner@example.com', $mail['recipient'] );
		$this->assertSame( 'Bistro "Table booking"', $mail['subject'] );
		$this->assertSame( array( 'From: Bistro <wordpress@example.com>', 'Reply-To: sarah@example.com' ), $mail['headers'] );
		$this->assertStringContainsString( "Message Body:\nHi, can I book a table for four?\nThanks", $mail['body'] );
		$this->assertStringContainsString( 'Services: Dinner, Drinks', $mail['body'] );
		$this->assertStringContainsString( 'Unknown: [not-a-field]', $mail['body'] );
		$this->assertStringContainsString( 'Sent from Bistro (https://bistro.example) at 10 November 2026', $mail['body'] );
	}

	public function test_compose_html_escapes_values_and_exclude_blank_drops_empty_lines() {
		$mail = Cf7Fields::compose(
			array(
				'recipient'     => 'owner@example.com',
				'sender'        => 'site@example.com',
				'subject'       => 'New message',
				'body'          => "Name: [your-name]\nPhone: [your-phone]\nMessage:\n[your-message]",
				'use_html'      => true,
				'exclude_blank' => true,
			),
			array(
				'your-name'    => '<b>Jo</b>',
				'your-phone'   => '',
				'your-message' => "Line one\nLine two",
			),
			array()
		);
		$this->assertContains( 'Content-Type: text/html', $mail['headers'] );
		$this->assertStringContainsString( 'Name: &lt;b&gt;Jo&lt;/b&gt;', $mail['body'] );
		$this->assertStringNotContainsString( 'Phone:', $mail['body'] );
		$this->assertStringContainsString( "Message:\nLine one<br />\nLine two", $mail['body'] );
	}

	public function test_compose_strips_newlines_from_header_fields() {
		$mail = Cf7Fields::compose(
			array(
				'recipient' => 'owner@example.com',
				'sender'    => '[your-name] <site@example.com>',
				'subject'   => '[your-subject]',
				'body'      => '',
			),
			array(
				'your-name'    => "Evil\r\nBcc: victim@example.com",
				'your-subject' => "Hi\nthere",
			),
			array()
		);
		$this->assertSame( 'From: Evil  Bcc: victim@example.com <site@example.com>', $mail['headers'][0] );
		$this->assertSame( 'Hi there', $mail['subject'] );
	}
}
