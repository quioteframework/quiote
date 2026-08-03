<?php
namespace Quiote\Security\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Parses an `Authorization` header into its scheme and credential, the way
 * RFC 9110 §11.6.2 actually specifies it rather than the way the wire format
 * usually looks.
 *
 * Two things every authenticator got wrong when doing this itself:
 *
 *  - **The scheme is case-insensitive.** Comparing it with `str_starts_with($h,
 *    'Bearer ')` rejects the legal `bearer eyJ...` that some clients and proxies
 *    emit. The request then carries no supported credential, falls through as
 *    unauthenticated, and surfaces as a login forward rather than the 401 the
 *    entry point exists to produce -- the same confusion the "bare Bearer" note
 *    in {@see BearerTokenAuthenticator} already warns about, one step out.
 *  - **The separator is a run of whitespace, not one space.** `substr($h,
 *    strlen('Bearer '))` on `Bearer  token` yields a credential with a leading
 *    space, which then fails signature verification (or base64 decoding) with an
 *    error that says nothing about the real cause.
 *
 * A declared scheme with an empty credential returns `''`, not null: the caller
 * did declare the scheme, so it must be answered with a 401 challenge rather than
 * treated as "no credential presented".
 * @since      3.0.3
 */
final class AuthorizationHeader
{
	private function __construct()
	{
	}

	/**
	 * The credential following $scheme in $request's `Authorization` header.
	 *
	 * @param      ServerRequestInterface $request The incoming request.
	 * @param      string $scheme The expected auth scheme, e.g. `Bearer` or `Basic`. Matched case-insensitively.
	 * @return     ?string The credential (possibly `''` when the scheme was declared without one), or null when
	 *             the header is absent or declares a different scheme.
	 * @since      3.0.3
	 */
	public static function credential(ServerRequestInterface $request, string $scheme): ?string
	{
		$header = trim($request->getHeaderLine('Authorization'));
		if($header === '') {
			return null;
		}

		// Limit 2, so a credential that somehow contains whitespace is handed back
		// intact rather than truncated at its first space.
		$parts = preg_split('/\s+/', $header, 2);
		if($parts === false) {
			return null;
		}

		if(strcasecmp($parts[0], $scheme) !== 0) {
			return null;
		}

		return $parts[1] ?? '';
	}

	/**
	 * Whether $request declares $scheme at all, regardless of whether it supplied
	 * a credential with it. This is the `supports()` question.
	 *
	 * @param      ServerRequestInterface $request The incoming request.
	 * @param      string $scheme The expected auth scheme. Matched case-insensitively.
	 * @return     bool True if the header declares $scheme, otherwise false.
	 * @since      3.0.3
	 */
	public static function declares(ServerRequestInterface $request, string $scheme): bool
	{
		return self::credential($request, $scheme) !== null;
	}
}
