<?php
/**
 * Stub Zeny Merchant API for the integration suite, served by `php -S`.
 * State lives in a JSON file that tests read and write through StubZeny.
 * It mimics the contract: idempotent create (same key, same link), link
 * status and paidCount, cancel rules, payments, and the error shape.
 */

const STATE = '/tmp/zeny-stub/state.json';

$method = $_SERVER['REQUEST_METHOD'];
$path   = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$query  = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY );
$body   = (string) file_get_contents( 'php://input' );
$hdrs   = array_change_key_case( getallheaders(), CASE_LOWER );

$fh = fopen( STATE, 'c+' );
flock( $fh, LOCK_EX );
$state = json_decode( (string) stream_get_contents( $fh ), true ) ?: array();
$state += array( 'links' => array(), 'keys' => array(), 'payments' => array(), 'failures' => array(), 'requests' => array() );

$state['requests'][] = array(
	'method'  => $method,
	'path'    => $path,
	'query'   => $query,
	'key'     => $hdrs['idempotency-key'] ?? null,
	'auth'    => $hdrs['authorization'] ?? null,
	'body'    => '' === $body ? null : json_decode( $body, true ),
);

/**
 * Answers and saves the state.
 */
function answer( array &$state, $fh, int $status, array $payload ): void {
	ftruncate( $fh, 0 );
	rewind( $fh );
	fwrite( $fh, json_encode( $state ) );
	flock( $fh, LOCK_UN );
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	header( 'X-Request-ID: req_stub_' . count( $state['requests'] ) );
	echo json_encode( $payload );
	exit;
}

function error( string $type, string $code ): array {
	return array( 'error' => array( 'type' => $type, 'code' => $code, 'message' => $code, 'param' => null, 'details' => array(), 'requestId' => 'req_err' ) );
}

if ( 0 === strpos( (string) ( $hdrs['authorization'] ?? '' ), 'Bearer sk_test_bad' ) ) {
	answer( $state, $fh, 401, error( 'authentication', 'token_invalid' ) );
}

// A queued failure answers instead of the route; "commit" applies the route first (an unknown outcome).
$failure = null;
foreach ( $state['failures'] as $i => $f ) {
	if ( $f['method'] === $method && 0 === strpos( $path, $f['path'] ) ) {
		$failure = $f;
		if ( --$state['failures'][ $i ]['count'] <= 0 ) {
			array_splice( $state['failures'], $i, 1 );
		}
		break;
	}
}
if ( null !== $failure && ! $failure['commit'] ) {
	answer( $state, $fh, $failure['status'], error( 'api_error', $failure['code'] ) );
}

$prefix = '/api/merchant/v1';
$status = 404;
$out    = error( 'not_found', 'not_found' );

if ( 'POST' === $method && $prefix . '/payment-links' === $path ) {
	$key = $hdrs['idempotency-key'] ?? '';
	if ( '' === $key ) {
		$status = 400;
		$out    = error( 'invalid_request', 'idempotency_key_required' );
	} elseif ( isset( $state['keys'][ $key ] ) ) {
		$status = 201;
		$out    = $state['links'][ $state['keys'][ $key ] ];
	} else {
		$req = json_decode( $body, true );
		$id  = 'pl_' . substr( sha1( $key ), 0, 16 );
		$out = array(
			'id'                  => $id,
			'object'              => 'payment_link',
			'name'                => $req['name'],
			'amount'              => $req['amount'] ?? null,
			'status'              => 'active',
			'instruments'         => $req['instruments'] ?? array(),
			'successRedirectUrl'  => $req['successRedirectUrl'] ?? '',
			'url'                 => 'https://pay.zeny.test/l/' . $id,
			'paidCount'           => 0,
			'createdAt'           => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'expiresAt'           => $req['expiresAt'] ?? null,
			'description'         => $req['description'] ?? '',
			'maxPayments'         => $req['maxPayments'] ?? 0,
			'requiredPayerFields' => $req['requiredPayerFields'] ?? array(),
			'expiredRedirectUrl'  => $req['expiredRedirectUrl'] ?? '',
		);
		$state['links'][ $id ]  = $out;
		$state['keys'][ $key ] = $id;
		$status                = 201;
	}
} elseif ( 'GET' === $method && $prefix . '/payment-links' === $path ) {
	$status = 200;
	$out    = array( 'data' => array_slice( array_values( $state['links'] ), 0, 1 ), 'nextCursor' => '' );
} elseif ( preg_match( '#^' . $prefix . '/payment-links/(pl_[\w-]+)(/cancel)?$#', $path, $m ) && isset( $state['links'][ $m[1] ] ) ) {
	$link = &$state['links'][ $m[1] ];
	if ( 'GET' === $method && empty( $m[2] ) ) {
		$status = 200;
		$out    = $link;
	} elseif ( 'POST' === $method && ! empty( $m[2] ) ) {
		if ( $link['paidCount'] > 0 ) {
			$status = 409;
			$out    = error( 'conflict', 'payment_link_has_payments' );
		} else {
			$link['status'] = 'canceled';
			$status         = 200;
			$out            = $link;
		}
	}
	unset( $link );
} elseif ( 'GET' === $method && preg_match( '#^' . $prefix . '/payments/(pay_[\w-]+)$#', $path, $m ) && isset( $state['payments'][ $m[1] ] ) ) {
	$status = 200;
	$out    = $state['payments'][ $m[1] ];
}

if ( null !== $failure ) {
	answer( $state, $fh, $failure['status'], error( 'api_error', $failure['code'] ) );
}
answer( $state, $fh, $status, $out );
