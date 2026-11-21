<?php
/**
 * Payment link to create for an order.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Domain;

use InvalidArgumentException;

/**
 * The CreatePaymentLinkRequest body for one checkout attempt, with the API's
 * limits applied so a long product name or an http site never makes the
 * request fail: text is cut to length, and redirect URLs that are not https are left out.
 */
final class LinkSpec {

	public const MIN_LIFETIME_MINUTES = 15;
	public const MAX_LIFETIME_MINUTES = 10080;

	private const NAME_MAX         = 120;
	private const DESCRIPTION_MAX  = 500;
	private const URL_MAX          = 2048;
	private const INSTRUMENTS_MAX  = 30;
	private const PAYER_FIELDS     = array( 'name', 'email', 'phone' );
	private const INSTRUMENT_REGEX = '/^[A-Z0-9_]{1,32}$/D';

	/**
	 * Link name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Amount every payer pays.
	 *
	 * @var Money
	 */
	private $amount;

	/**
	 * Description, "" for none.
	 *
	 * @var string
	 */
	private $description;

	/**
	 * Https URL for a payer who paid, or null.
	 *
	 * @var string|null
	 */
	private $successRedirectUrl;

	/**
	 * Https URL for a payer who opens the expired link, or null.
	 *
	 * @var string|null
	 */
	private $expiredRedirectUrl;

	/**
	 * Lifetime in minutes, within the allowed range.
	 *
	 * @var int
	 */
	private $lifetimeMinutes;

	/**
	 * Instrument codes, [] for every instrument.
	 *
	 * @var list<string>
	 */
	private $instruments;

	/**
	 * Payer fields the payer must give, in API order.
	 *
	 * @var list<string>
	 */
	private $requiredPayerFields;

	/**
	 * Builds the spec.
	 *
	 * @param string        $name                  What the payer sees the link is for.
	 * @param Money         $amount                Order total.
	 * @param array<string> $item_names            Names of the order's items, for the description.
	 * @param string|null   $success_redirect_url  Order-received page.
	 * @param string|null   $expired_redirect_url  Page to pay the order again.
	 * @param int           $lifetime_minutes      How long the link takes payments.
	 * @param array<string> $instruments           Instrument codes such as QRIS or BCA.
	 * @param array<string> $required_payer_fields Any of name, email, phone.
	 * @throws InvalidArgumentException When the name is empty or the amount is not positive.
	 */
	public function __construct(
		string $name,
		Money $amount,
		array $item_names,
		?string $success_redirect_url,
		?string $expired_redirect_url,
		int $lifetime_minutes,
		array $instruments,
		array $required_payer_fields
	) {
		$this->name = self::clip( $name, self::NAME_MAX );
		if ( '' === $this->name ) {
			throw new InvalidArgumentException( 'A payment link needs a name.' );
		}
		if ( ! $amount->isPositive() ) {
			throw new InvalidArgumentException( 'A payment link needs a positive amount.' );
		}
		$this->amount              = $amount;
		$this->description         = self::clip( implode( ', ', $item_names ), self::DESCRIPTION_MAX );
		$this->successRedirectUrl  = self::httpsUrl( $success_redirect_url );
		$this->expiredRedirectUrl  = self::httpsUrl( $expired_redirect_url );
		$this->lifetimeMinutes     = max( self::MIN_LIFETIME_MINUTES, min( self::MAX_LIFETIME_MINUTES, $lifetime_minutes ) );
		$this->instruments         = self::instrumentCodes( $instruments );
		$this->requiredPayerFields = array_values( array_intersect( self::PAYER_FIELDS, $required_payer_fields ) );
	}

	/**
	 * Whether the payer is sent back to the shop after paying. False when the
	 * shop is not on https, which the API refuses for redirect URLs.
	 */
	public function hasSuccessRedirect(): bool {
		return null !== $this->successRedirectUrl;
	}

	/**
	 * The request body. One link takes one payment: an order is paid once.
	 *
	 * @param int $now Unix time the link's lifetime starts from.
	 * @return array<string, mixed>
	 */
	public function toRequestBody( int $now ): array {
		$body     = array(
			'name'        => $this->name,
			'amount'      => $this->amount->toArray(),
			'maxPayments' => 1,
			'expiresAt'   => gmdate( 'Y-m-d\TH:i:s\Z', $now + 60 * $this->lifetimeMinutes ),
		);
		$optional = array(
			'description'         => $this->description,
			'successRedirectUrl'  => $this->successRedirectUrl,
			'expiredRedirectUrl'  => $this->expiredRedirectUrl,
			'instruments'         => $this->instruments,
			'requiredPayerFields' => $this->requiredPayerFields,
		);
		foreach ( $optional as $field => $value ) {
			if ( null !== $value && '' !== $value && array() !== $value ) {
				$body[ $field ] = $value;
			}
		}
		return $body;
	}

	/**
	 * One line of at most $max characters, trimmed, without control characters
	 * (the API refuses them).
	 *
	 * @param string $text Input.
	 * @param int    $max  Character limit.
	 */
	private static function clip( string $text, int $max ): string {
		$line = preg_replace( '/[\p{Cc}\s]+/u', ' ', $text );
		return trim( mb_substr( trim( (string) $line ), 0, $max ) );
	}

	/**
	 * The URL when it is an absolute https URL the API accepts, else null.
	 *
	 * @param string|null $url Candidate URL.
	 */
	private static function httpsUrl( ?string $url ): ?string {
		if ( null === $url || strlen( $url ) > self::URL_MAX || 1 !== preg_match( '~^https://[^/\s?#]+[^\s]*$~iD', $url ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * Upper-case, valid, unique codes, at most 30.
	 *
	 * @param array<string> $codes Codes from the settings.
	 * @return list<string>
	 */
	private static function instrumentCodes( array $codes ): array {
		$valid = array();
		foreach ( $codes as $code ) {
			$code = strtoupper( trim( $code ) );
			if ( 1 === preg_match( self::INSTRUMENT_REGEX, $code ) && ! in_array( $code, $valid, true ) ) {
				$valid[] = $code;
			}
		}
		return array_slice( $valid, 0, self::INSTRUMENTS_MAX );
	}
}
