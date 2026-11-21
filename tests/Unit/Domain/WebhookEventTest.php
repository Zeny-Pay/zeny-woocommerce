<?php

namespace Zeny\WooCommerce\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zeny\WooCommerce\Domain\WebhookEvent;

final class WebhookEventTest extends TestCase {

	public function test_reads_the_envelope(): void {
		$event = WebhookEvent::fromArray(
			array(
				'id'        => 'evt_1',
				'type'      => 'payment.paid',
				'mode'      => 'live',
				'createdAt' => '2026-10-21T03:15:00Z',
				'data'      => array( 'object' => array( 'id' => 'pay_1' ) ),
			)
		);
		$this->assertSame( 'evt_1', $event->id() );
		$this->assertSame( 'payment.paid', $event->type() );
		$this->assertSame( 'live', $event->mode() );
		$this->assertSame( 1792552500, $event->createdAt() );
		$this->assertSame( array( 'id' => 'pay_1' ), $event->object() );
	}

	public function test_optional_parts_default_safely(): void {
		$event = WebhookEvent::fromArray( array( 'id' => 'evt_1', 'type' => 'x', 'createdAt' => 'garbage', 'data' => 'no' ) );
		$this->assertSame( '', $event->mode() );
		$this->assertNull( $event->createdAt() );
		$this->assertSame( array(), $event->object() );
	}

	/**
	 * @dataProvider invalid
	 *
	 * @param array<string, mixed> $payload Event payload.
	 */
	public function test_requires_id_and_type( array $payload ): void {
		$this->expectException( InvalidArgumentException::class );
		WebhookEvent::fromArray( $payload );
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public function invalid(): array {
		return array(
			'no id'       => array( array( 'type' => 'payment.paid' ) ),
			'empty id'    => array( array( 'id' => '', 'type' => 'payment.paid' ) ),
			'numeric id'  => array( array( 'id' => 1, 'type' => 'payment.paid' ) ),
			'no type'     => array( array( 'id' => 'evt_1' ) ),
		);
	}
}
