<?php
/**
 * Deal with importing all those nasty globals and things
 *
 * Copyright © 2003 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Session\Session;
use MediaWiki\Session\SessionId;
use MediaWiki\Session\SessionManager;
use Wikimedia\IPUtils;

// The point of this class is to be a wrapper around super globals
// phpcs:disable MediaWiki.Usage.SuperGlobalsUsage.SuperGlobals

/**
 * The WebRequest class encapsulates getting at data passed in the
 * URL or via a POSTed form stripping illegal input characters and
 * normalizing Unicode sequences.
 *
 * @ingroup HTTP
 */
class WebRequest {
	/**
	 * The parameters from $_GET, $_POST and the path router
	 * @var array
	 */
	protected $data;

	/**
	 * Web request URL with query params.
	 * @var string
	 */
	protected $requestUrl;

	/**
	 * The PATH_INFO or the path portion of the request URI.
	 * @var string
	 */
	protected $requestPath;

	/**
	 * Name of default entry point.
	 * @var string
	 */
	public $defaultEntryPoint = 'index';

	/**
	 * Name of the entry point for the request.
	 * Initialized by detectEntryPoint().
	 * @var string
	 */
	protected $entryPoint;

	/**
	 * The subpath of the request URL within the entry point's path.
	 * @var string
	 */
	protected $entryPointSubpath;

	/**
	 * The parameters from $_GET. The parameters from the path router are
	 * added by interpolateTitle() during Setup.php.
	 * @var string[]
	 */
	protected $queryAndPathParams;

	/**
	 * The parameters from $_GET only.
	 * @var string[]
	 */
	protected $queryParams;

	/**
	 * Lazy-initialized request headers indexed by upper-case header name
	 * @var string[]
	 */
	protected $headers = [];

	/**
	 * Web request URL if failed to parse.
	 * @var ?string
	 */
	protected $badPath;

	/**
	 * Flag to make WebRequest::getHeader return an array of values.
	 * @since 1.26
	 */
	public const GETHEADER_LIST = 1;

	/**
	 * The unique request ID.
	 * @var string
	 */
	private static $reqId;

	/**
	 * Lazy-init response object
	 * @var WebResponse
	 */
	private $response;

	/**
	 * Cached client IP address
	 * @var string
	 */
	private $ip;

	/**
	 * The timestamp of the start of the request, with microsecond precision.
	 * @var float
	 */
	protected $requestTime;

	/**
	 * Cached URL protocol
	 * @var string
	 */
	protected $protocol;

	/**
	 * @var SessionId|null Session ID to use for this
	 *  request. We can't save the session directly due to reference cycles not
	 *  working too well (slow GC).
	 *
	 * TODO: Investigate whether this GC slowness concern (added in a73c5b7395 with regard to
	 * PHP 5.6) still applies in PHP 7.2+.
	 */
	protected $sessionId = null;

	/** @var bool Whether this HTTP request is "safe" (even if it is an HTTP post) */
	protected $markedAsSafe = false;

	/**
	 * @codeCoverageIgnore
	 */
	public function __construct() {
		$this->requestTime = $_SERVER['REQUEST_TIME_FLOAT'];

		// POST overrides GET data
		// We don't use $_REQUEST here to avoid interference from cookies...
		$this->data = $_POST + $_GET;

		$this->queryAndPathParams = $this->queryParams = $_GET;
	}

	/**
	 * Extract relevant query arguments from the http request uri's path
	 * to be merged with the normal php provided query arguments.
	 * Tries to use the REQUEST_URI data if available and parses it
	 * according to the wiki's configuration looking for any known pattern.
	 *
	 * If the REQUEST_URI is not provided we'll fall back on the PATH_INFO
	 * provided by the server if any and use that to set a 'title' parameter.
	 *
	 * If none of the rules match, the whole PATH_INFO is used as 'title'.
	 *
	 * This internal method handles many odd cases and is tailored specifically for
	 * used by WebRequest::interpolateTitle, for index.php requests.
	 * Consider using WebRequest::getRequestPathSuffix for other path-related use cases.
	 *
	 * Deprecated since 1.36: always called with 'title',
	 * default 'all' is pointless: matched '/w/index.php' as title.
	 * @param string $want If this is not 'all', then the function
	 * will return an empty array if it determines that the URL is
	 * inside a rewrite path.
	 *
	 * @return string[] Any query arguments found in path matches.
	 * @throws FatalError If invalid routes are configured (T48998)
	 */
	protected static function getPathInfo( $want = 'all' ) {
		$path = parse_url( self::getGlobalRequestURL(), PHP_URL_PATH );
		if ( $path ) {
			return self::getPathMatches( $path );
		} else {
			$pathInfo = self::getGlobalRequestPathInfo();
			return $pathInfo === null ? null : [ 'title' => $pathInfo ];
		}
	}

	/**
	 * Extract relevant query arguments from $path.
	 *
	 * @internal This has many odd special cases and so should only be used by
	 *   interpolateTitle() for index.php. Instead try getRequestPathSuffix().
	 *
	 * @param string $path to parse.
	 *
	 * @return array Any query arguments found in path matches.
	 * @throws FatalError If invalid routes are configured (T48998)
	 */
	public static function getPathMatches( $path ) {
		{ // Keeping indent to retain git blame history.
			if ( $path === '' || $path === '/' ) {
				return []; // Meaning: successful parse, no title given, redirect to Main_Page.
			}

			$router = new PathRouter;

			self::addPageRoutes( $router );

			global $wgArticlePath;
			if ( $wgArticlePath ) {
				$router->validateRoute( $wgArticlePath, 'wgArticlePath' );
				$router->add( $wgArticlePath );
			}

			global $wgActionPaths;
			if ( $wgActionPaths ) {
				$router->add( $wgActionPaths, [ 'action' => '$key' ] );
			}

			global $wgExtraRouterPaths;
			foreach ( ( $wgExtraRouterPaths ?? [] ) as $pathPattern => $params ) {
				$router->add( $pathPattern, $params );
			}

			global $wgVariantArticlePath;
			if ( $wgVariantArticlePath ) {
				$router->validateRoute( $wgVariantArticlePath, 'wgVariantArticlePath' );
				$router->add( $wgVariantArticlePath,
					[ 'variant' => '$2' ],
					[ '$2' => MediaWikiServices::getInstance()->getContentLanguage()->
					getVariants() ]
				);
			}

			Hooks::runner()->onWebRequestPathInfoRouter( $router );

			$matches = $router->parse( $path );
		}

		return $matches;
	}

	/**
	 * Add page routes with namespace and action.
	 *
	 * @since 1.36
	 * @param PathRouter $router
	 */
	protected static function addPageRoutes( $router ) {
		global $wgArticlePathWithAction, $wgValidActions;
		//$wgValidActions = array_keys( $wgActionPaths );
		if ( !$wgArticlePathWithAction ) {
			return;
		}

		$routeParams = [
			'title' => '$1',
			'action' => '$2',
		];
		$routeConstraints = [
			'$2' => $wgValidActions,
		];
		$route = $wgArticlePathWithAction;
		foreach ( $routeParams as $key => $value ) {
			// Replace '$title' -> '$1', etc.
			$route = str_replace( '$' . $key, $value, $route );
		}
		$router->add( $route, $routeParams, $routeConstraints );
	}

	/**
	 * If the request URL matches a given base path, extract the path part of
	 * the request URL after that base, and decode escape sequences in it.
	 *
	 * If the request URL does not match, false is returned.
	 *
	 * @since 1.35
	 * @param string $basePath The base URL path. Trailing slashes will be
	 *   stripped.
	 * @return string|false
	 */
	public static function getRequestPathSuffix( $basePath ) {
		$basePath = rtrim( $basePath, '/' ) . '/';
		$requestUrl = self::getGlobalRequestURL();
		$qpos = strpos( $requestUrl, '?' );
		if ( $qpos !== false ) {
			$requestPath = substr( $requestUrl, 0, $qpos );
		} else {
			$requestPath = $requestUrl;
		}
		//$requestPath = self::parseURLPath( self::getGlobalRequestURL() );
		if ( substr( $requestPath, 0, strlen( $basePath ) ) !== $basePath ) {
			return false;
		}
		return rawurldecode( substr( $requestPath, strlen( $basePath ) ) );
	}

	/**
	 * Work out an appropriate URL prefix containing scheme and host, based on
	 * information detected from $_SERVER
	 *
	 * @return string
	 */
	public static function detectServer() {
		global $wgAssumeProxiesUseDefaultProtocolPorts;

		$proto = self::detectProtocol();
		$stdPort = $proto === 'https' ? 443 : 80;

		$varNames = [ 'HTTP_HOST', 'SERVER_NAME', 'HOSTNAME', 'SERVER_ADDR' ];
		$host = 'localhost';
		$port = $stdPort;
		foreach ( $varNames as $varName ) {
			if ( !isset( $_SERVER[$varName] ) ) {
				continue;
			}

			$parts = IPUtils::splitHostAndPort( $_SERVER[$varName] );
			if ( !$parts ) {
				// Invalid, do not use
				continue;
			}

			$host = $parts[0];
			if ( $wgAssumeProxiesUseDefaultProtocolPorts && isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
				// T72021: Assume that upstream proxy is running on the default
				// port based on the protocol. We have no reliable way to determine
				// the actual port in use upstream.
				$port = $stdPort;
			} elseif ( $parts[1] === false ) {
				if ( isset( $_SERVER['SERVER_PORT'] ) ) {
					$port = $_SERVER['SERVER_PORT'];
				} // else leave it as $stdPort
			} else {
				$port = $parts[1];
			}
			break;
		}

		return $proto . '://' . IPUtils::combineHostAndPort( $host, $port, $stdPort );
	}

	/**
	 * Detect the protocol from $_SERVER.
	 * This is for use prior to Setup.php, when no WebRequest object is available.
	 * At other times, use the non-static function getProtocol().
	 *
	 * @return string
	 */
	public static function detectProtocol() {
		if ( ( !empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ||
			( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) &&
			$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) ) {
			return 'https';
		} else {
			return 'http';
		}
	}

	/**
	 * Return the name of the first entrypoint that matches (as prefix) the request's path.
	 * Note: the first match is returned, even if there is a more specific
	 * prefix later in the array.
	 * The order in which entries are added to the array is relevant,
	 * the first match is returned.
	 *
	 * This prioritizes the URL part after 'index.php/' (PATH_INFO)
	 * - if enabled and available - over REQUEST_URI, allowing
	 * the rewrite of the parsed subpath in the `.htaccess` file.
	 *
	 * @param array $entryPointPaths Entry point -> path prefix mapping.
	 * @return ?string name of first mathing entrypoint or null for default.
	 * @throws ConfigException if a non-default entry point's path is ''.
	 */
	public function detectEntryPoint( array $entryPointPaths ) : ?string {
		$path = $this->getRequestPath();

		foreach ( $entryPointPaths as $name => $prefix ) {
			if ( !$prefix ) {
				if ( $prefix === '' && $name !== $this->defaultEntryPoint ) {
					throw new ConfigException( "Entry point '". $name . "'s path is empty. Only the default entry point's path can be empty." );
				}
				continue;
			}
			$prefixLen = strlen( $prefix );
			$subpathSep = substr( $path, $prefixLen, 1 );
			if ( ( $subpathSep === '/' || $subpathSep === '' ) && $prefix === substr( $path, 0, $prefixLen ) ) {
				$this->entryPointSubpath = substr( $path, $prefixLen );
				$this->entryPoint = $name;
				return $this->entryPoint;
			}
		}
		$this->entryPointSubpath = $path;
		$this->entryPoint = $this->defaultEntryPoint;
		return $this->entryPoint;
	}

	/**
	 * Set the entry point in use. Should be called with MW_ENTRY_POINT when defined.
	 *
	 * @param string $entryPoint Name of entry point.
	 */
	public function setEntryPoint( string $entryPoint ) {
		global $wgScriptPath;
		$this->entryPoint = $entryPoint;
		$this->entryPointSubpath = self::getGlobalRequestPathInfo( "$wgScriptPath/$entryPoint.php" );
	}

	/**
	 * Get the number of seconds to have elapsed since request start,
	 * in fractional seconds, with microsecond resolution.
	 *
	 * @return float
	 * @since 1.25
	 */
	public function getElapsedTime() {
		return microtime( true ) - $this->requestTime;
	}

	/**
	 * Get the current request ID.
	 *
	 * This is usually based on the `X-Request-Id` header, or the `UNIQUE_ID`
	 * environment variable, falling back to (process cached) randomly-generated string.
	 *
	 * @return string
	 * @since 1.27
	 */
	public static function getRequestId() {
		// This method is called from various error handlers and MUST be kept simple and stateless.
		if ( !self::$reqId ) {
			global $wgAllowExternalReqID;
			if ( $wgAllowExternalReqID ) {
				$id = $_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['UNIQUE_ID'] ?? wfRandomString( 24 );
			} else {
				$id = $_SERVER['UNIQUE_ID'] ?? wfRandomString( 24 );
			}
			self::$reqId = $id;
		}

		return self::$reqId;
	}

	/**
	 * Override the unique request ID. This is for sub-requests, such as jobs,
	 * that wish to use the same id but are not part of the same execution context.
	 *
	 * @param string $id
	 * @since 1.27
	 */
	public static function overrideRequestId( $id ) {
		self::$reqId = $id;
	}

	/**
	 * Get the current URL protocol (http or https)
	 * @return string
	 */
	public function getProtocol() {
		if ( $this->protocol === null ) {
			$this->protocol = self::detectProtocol();
		}
		return $this->protocol;
	}

	/**
	 * Check for title, action, and/or variant data in the URL
	 * and interpolate it into the GET variables.
	 * This should only be run after the content language is available,
	 * as we may need the list of language variants to determine
	 * available variant URLs.
	 */
	public function interpolateTitle() {
		// T18019: title interpolation on API queries is useless and sometimes harmful
		if ( defined( 'MW_API' ) ) {
			return;
		}

		// Since 1.36: change from getPathInfo():
		// entryPointSubpath prioritizes the URL part after 'index.php/' (PATH_INFO)
		// - if enabled and available - over REQUEST_URI, allowing
		// the rewrite of the parsed subpath in the `.htaccess` file.
		$matches = self::getPathMatches( $this->entryPointSubpath );

		// Backwards compatibility: use whole PATH_INFO as title
		// if the routes didn't match (seldom effective).
		if ( $matches === null ) {
			$pathInfo = self::getGlobalRequestPathInfo();
			$title = wfRemovePrefix( $pathInfo ?? '', '/' );
			if ( $title ) {
				$matches = [ 'title' => $title ];
			} elseif ( $pathInfo === null ) {
				$this->badPath = $this->entryPointSubpath;
				return;
			}
		}

		foreach ( $matches as $key => $val ) {
			$this->data[$key] = $this->queryAndPathParams[$key] = $val;
		}
	}

	/**
	 * URL rewriting function; tries to extract page title and,
	 * optionally, one other fixed parameter value from a URL path.
	 *
	 * @param string $path The URL path given from the client
	 * @param array $bases One or more URLs, optionally with $1 at the end
	 * @param string|false $key If provided, the matching key in $bases will be
	 *    passed on as the value of this URL parameter
	 * @return array Array of URL variables to interpolate; empty if no match
	 */
	public static function extractTitle( $path, $bases, $key = false ) {
		foreach ( (array)$bases as $keyValue => $base ) {
			// Find the part after $wgArticlePath
			$base = str_replace( '$1', '', $base );
			$baseLen = strlen( $base );
			if ( substr( $path, 0, $baseLen ) == $base ) {
				$raw = substr( $path, $baseLen );
				if ( $raw !== '' ) {
					$matches = [ 'title' => rawurldecode( $raw ) ];
					if ( $key ) {
						$matches[$key] = $keyValue;
					}
					return $matches;
				}
			}
		}
		return [];
	}

	/**
	 * Recursively normalizes UTF-8 strings in the given array.
	 *
	 * @param string|array $data
	 * @return array|string Cleaned-up version of the given
	 * @internal
	 */
	public function normalizeUnicode( $data ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $val ) {
				$data[$key] = $this->normalizeUnicode( $val );
			}
		} else {
			$contLang = MediaWikiServices::getInstance()->getContentLanguage();
			$data = $contLang->normalize( $data );
		}
		return $data;
	}

	/**
	 * Return the subpath of the request URL within the entry point's path.
	 *
	 * @return string
	 */
	public function getEntryPointSubpath() {
		return $this->entryPointSubpath;
	}

	/**
	 * Fetch a value from the given array or return $default if it's not set.
	 *
	 * @param array $arr
	 * @param string $name
	 * @param mixed $default
	 * @return mixed
	 */
	private function getGPCVal( $arr, $name, $default ) {
		# PHP is so nice to not touch input data, except sometimes:
		# https://www.php.net/variables.external#language.variables.external.dot-in-names
		# Work around PHP *feature* to avoid *bugs* elsewhere.
		$name = strtr( $name, '.', '_' );

		if ( !isset( $arr[$name] ) ) {
			return $default;
		}

		$data = $arr[$name];
		# Optimisation: Skip UTF-8 normalization and legacy transcoding for simple ASCII strings.
		$isAsciiStr = ( is_string( $data ) && preg_match( '/[^\x20-\x7E]/', $data ) === 0 );
		if ( !$isAsciiStr ) {
			if ( isset( $_GET[$name] ) && is_string( $data ) ) {
				# Check for alternate/legacy character encoding.
				$data = MediaWikiServices::getInstance()
					->getContentLanguage()
					->checkTitleEncoding( $data );
			}
			$data = $this->normalizeUnicode( $data );
		}

		return $data;
	}

	/**
	 * Fetch a scalar from the input without normalization, or return $default
	 * if it's not set.
	 *
	 * Unlike self::getVal(), this does not perform any normalization on the
	 * input value.
	 *
	 * @since 1.28
	 * @param string $name
	 * @param string|null $default
	 * @return string|null
	 */
	public function getRawVal( $name, $default = null ) {
		$name = strtr( $name, '.', '_' ); // See comment in self::getGPCVal()
		if ( isset( $this->data[$name] ) && !is_array( $this->data[$name] ) ) {
			$val = $this->data[$name];
		} else {
			$val = $default;
		}
		if ( $val === null ) {
			return $val;
		} else {
			return (string)$val;
		}
	}

	/**
	 * Fetch a scalar from the input or return $default if it's not set.
	 * Returns a string. Arrays are discarded. Useful for
	 * non-freeform text inputs (e.g. predefined internal text keys
	 * selected by a drop-down menu). For freeform input, see getText().
	 *
	 * @param string $name
	 * @param string|null $default Optional default (or null)
	 * @return string|null
	 */
	public function getVal( $name, $default = null ) {
		$val = $this->getGPCVal( $this->data, $name, $default );
		if ( is_array( $val ) ) {
			$val = $default;
		}
		if ( $val === null ) {
			return $val;
		} else {
			return (string)$val;
		}
	}

	/**
	 * Set an arbitrary value into our get/post data.
	 *
	 * @param string $key Key name to use
	 * @param mixed $value Value to set
	 * @return mixed Old value if one was present, null otherwise
	 */
	public function setVal( $key, $value ) {
		$ret = $this->data[$key] ?? null;
		$this->data[$key] = $value;
		return $ret;
	}

	/**
	 * Unset an arbitrary value from our get/post data.
	 *
	 * @param string $key Key name to use
	 * @return mixed Old value if one was present, null otherwise
	 */
	public function unsetVal( $key ) {
		if ( !isset( $this->data[$key] ) ) {
			$ret = null;
		} else {
			$ret = $this->data[$key];
			unset( $this->data[$key] );
		}
		return $ret;
	}

	/**
	 * Fetch an array from the input or return $default if it's not set.
	 * If source was scalar, will return an array with a single element.
	 * If no source and no default, returns null.
	 *
	 * @param string $name
	 * @param array|null $default Optional default (or null)
	 * @return array|null
	 */
	public function getArray( $name, $default = null ) {
		$val = $this->getGPCVal( $this->data, $name, $default );
		if ( $val === null ) {
			return null;
		} else {
			return (array)$val;
		}
	}

	/**
	 * Fetch an array of integers, or return $default if it's not set.
	 * If source was scalar, will return an array with a single element.
	 * If no source and no default, returns null.
	 * If an array is returned, contents are guaranteed to be integers.
	 *
	 * @param string $name
	 * @param array|null $default Option default (or null)
	 * @return int[]|null
	 */
	public function getIntArray( $name, $default = null ) {
		$val = $this->getArray( $name, $default );
		if ( is_array( $val ) ) {
			$val = array_map( 'intval', $val );
		}
		return $val;
	}

	/**
	 * Fetch an integer value from the input or return $default if not set.
	 * Guaranteed to return an integer; non-numeric input will typically
	 * return 0.
	 *
	 * @param string $name
	 * @param int $default
	 * @return int
	 */
	public function getInt( $name, $default = 0 ) {
		return intval( $this->getRawVal( $name, $default ) );
	}

	/**
	 * Fetch an integer value from the input or return null if empty.
	 * Guaranteed to return an integer or null; non-numeric input will
	 * typically return null.
	 *
	 * @param string $name
	 * @return int|null
	 */
	public function getIntOrNull( $name ) {
		$val = $this->getRawVal( $name );
		return is_numeric( $val )
			? intval( $val )
			: null;
	}

	/**
	 * Fetch a floating point value from the input or return $default if not set.
	 * Guaranteed to return a float; non-numeric input will typically
	 * return 0.
	 *
	 * @since 1.23
	 * @param string $name
	 * @param float $default
	 * @return float
	 */
	public function getFloat( $name, $default = 0.0 ) {
		return floatval( $this->getRawVal( $name, $default ) );
	}

	/**
	 * Fetch a boolean value from the input or return $default if not set.
	 * Guaranteed to return true or false, with normal PHP semantics for
	 * boolean interpretation of strings.
	 *
	 * @param string $name
	 * @param bool $default
	 * @return bool
	 */
	public function getBool( $name, $default = false ) {
		return (bool)$this->getRawVal( $name, $default );
	}

	/**
	 * Fetch a boolean value from the input or return $default if not set.
	 * Unlike getBool, the string "false" will result in boolean false, which is
	 * useful when interpreting information sent from JavaScript.
	 *
	 * @param string $name
	 * @param bool $default
	 * @return bool
	 */
	public function getFuzzyBool( $name, $default = false ) {
		return $this->getBool( $name, $default )
			&& strcasecmp( $this->getRawVal( $name ), 'false' ) !== 0;
	}

	/**
	 * Return true if the named value is set in the input, whatever that
	 * value is (even "0"). Return false if the named value is not set.
	 * Example use is checking for the presence of check boxes in forms.
	 *
	 * @param string $name
	 * @return bool
	 */
	public function getCheck( $name ) {
		# Checkboxes and buttons are only present when clicked
		# Presence connotes truth, absence false
		return $this->getRawVal( $name, null ) !== null;
	}

	/**
	 * Fetch a text string from the given array or return $default if it's not
	 * set. Carriage returns are stripped from the text. This should generally
	 * be used for form "<textarea>" and "<input>" fields, and for
	 * user-supplied freeform text input.
	 *
	 * @param string $name
	 * @param string $default Optional
	 * @return string
	 */
	public function getText( $name, $default = '' ) {
		$val = $this->getVal( $name, $default );
		return str_replace( "\r\n", "\n", $val );
	}

	/**
	 * Extracts the (given) named values into an array.
	 * No transformation is performed on the values.
	 *
	 * @param string ...$names If no arguments are given, returns all input values
	 * @return array
	 */
	public function getValues( ...$names ) {
		if ( $names === [] ) {
			$names = array_keys( $this->data );
		}

		$retVal = [];
		foreach ( $names as $name ) {
			$value = $this->getGPCVal( $this->data, $name, null );
			if ( $value !== null ) {
				$retVal[$name] = $value;
			}
		}
		return $retVal;
	}

	/**
	 * Returns the names of all input values excluding those in $exclude.
	 *
	 * @param array $exclude
	 * @return array
	 */
	public function getValueNames( $exclude = [] ) {
		return array_diff( array_keys( $this->getValues() ), $exclude );
	}

	/**
	 * Get the values passed in the query string and the path router parameters.
	 * No transformation is performed on the values.
	 *
	 * @codeCoverageIgnore
	 * @return string[]
	 */
	public function getQueryValues() {
		return $this->queryAndPathParams;
	}

	/**
	 * Get the values passed in the query string only, not including the path
	 * router parameters. This is less suitable for self-links to index.php but
	 * useful for other entry points. No transformation is performed on the
	 * values.
	 *
	 * @since 1.34
	 * @return string[]
	 */
	public function getQueryValuesOnly() {
		return $this->queryParams;
	}

	/**
	 * Get the values passed via POST.
	 * No transformation is performed on the values.
	 *
	 * @since 1.32
	 * @codeCoverageIgnore
	 * @return string[]
	 */
	public function getPostValues() {
		return $_POST;
	}

	/**
	 * Return the contents of the Query with no decoding. Use when you need to
	 * know exactly what was sent, e.g. for an OAuth signature over the elements.
	 *
	 * @codeCoverageIgnore
	 * @return string
	 */
	public function getRawQueryString() {
		return $_SERVER['QUERY_STRING'];
	}

	/**
	 * Return the contents of the POST with no decoding. Use when you need to
	 * know exactly what was sent, e.g. for an OAuth signature over the elements.
	 *
	 * @return string
	 */
	public function getRawPostString() {
		if ( !$this->wasPosted() ) {
			return '';
		}
		return $this->getRawInput();
	}

	/**
	 * Return the raw request body, with no processing. Cached since some methods
	 * disallow reading the stream more than once. As stated in the php docs, this
	 * does not work with enctype="multipart/form-data".
	 *
	 * @return string
	 */
	public function getRawInput() {
		static $input = null;
		if ( $input === null ) {
			$input = file_get_contents( 'php://input' );
		}
		return $input;
	}

	/**
	 * Get the HTTP method used for this request.
	 *
	 * @return string
	 */
	public function getMethod() {
		return $_SERVER['REQUEST_METHOD'] ?? 'GET';
	}

	/**
	 * Returns true if the present request was reached by a POST operation,
	 * false otherwise (GET, HEAD, or command-line).
	 *
	 * Note that values retrieved by the object may come from the
	 * GET URL etc even on a POST request.
	 *
	 * @return bool
	 */
	public function wasPosted() {
		return $this->getMethod() == 'POST';
	}

	/**
	 * Return the session for this request
	 *
	 * This might unpersist an existing session if it was invalid.
	 *
	 * @since 1.27
	 * @note For performance, keep the session locally if you will be making
	 *  much use of it instead of calling this method repeatedly.
	 * @return Session
	 */
	public function getSession() {
		if ( $this->sessionId !== null ) {
			$session = SessionManager::singleton()->getSessionById( (string)$this->sessionId, true, $this );
			if ( $session ) {
				return $session;
			}
		}

		$session = SessionManager::singleton()->getSessionForRequest( $this );
		$this->sessionId = $session->getSessionId();
		return $session;
	}

	/**
	 * Set the session for this request
	 * @since 1.27
	 * @internal For use by MediaWiki\Session classes only
	 * @param SessionId $sessionId
	 */
	public function setSessionId( SessionId $sessionId ) {
		$this->sessionId = $sessionId;
	}

	/**
	 * Get the session id for this request, if any
	 * @since 1.27
	 * @internal For use by MediaWiki\Session classes only
	 * @return SessionId|null
	 */
	public function getSessionId() {
		return $this->sessionId;
	}

	/**
	 * Get a cookie from the $_COOKIE jar
	 *
	 * @param string $key The name of the cookie
	 * @param string|null $prefix A prefix to use for the cookie name, if not $wgCookiePrefix
	 * @param mixed|null $default What to return if the value isn't found
	 * @return mixed Cookie value or $default if the cookie not set
	 */
	public function getCookie( $key, $prefix = null, $default = null ) {
		if ( $prefix === null ) {
			global $wgCookiePrefix;
			$prefix = $wgCookiePrefix;
		}
		$name = $prefix . $key;
		// Work around mangling of $_COOKIE
		$name = strtr( $name, '.', '_' );
		return $_COOKIE[$name] ?? $default;
	}

	/**
	 * Get a cookie set with SameSite=None possibly with a legacy fallback cookie.
	 *
	 * @param string $key The name of the cookie
	 * @param string $prefix A prefix to use, empty by default
	 * @param mixed|null $default What to return if the value isn't found
	 * @return mixed Cookie value or $default if the cookie is not set
	 */
	public function getCrossSiteCookie( $key, $prefix = '', $default = null ) {
		global $wgUseSameSiteLegacyCookies;
		$name = $prefix . $key;
		// Work around mangling of $_COOKIE
		$name = strtr( $name, '.', '_' );
		if ( isset( $_COOKIE[$name] ) ) {
			return $_COOKIE[$name];
		}
		if ( $wgUseSameSiteLegacyCookies ) {
			$legacyName = $prefix . "ss0-" . $key;
			$legacyName = strtr( $legacyName, '.', '_' );
			if ( isset( $_COOKIE[$legacyName] ) ) {
				return $_COOKIE[$legacyName];
			}
		}
		return $default;
	}

	/**
	 * Return the path and query string portion of the main request URI.
	 * This will be suitable for use as a relative link in HTML output.
	 *
	 * @throws MWException
	 * @return string
	 */
	public static function getGlobalRequestURL() {
		// This method is called on fatal errors; it should not depend on anything complex.
		$base = $_SERVER['REQUEST_URI'] ?? null;
		$base = $base ?: ( $_SERVER['HTTP_X_ORIGINAL_URL'] ?? null ); // Probably IIS; doesn't set REQUEST_URI
		if ( !$base ) {
			$base = $_SERVER['SCRIPT_NAME'] ?? null;
			$query = $_SERVER['QUERY_STRING'] ?? null;
			if ( $base && $query ) {
				$base .= '?' . $query;
			}
		}
		if ( !$base ) {
			// This shouldn't happen!
			throw new MWException( "Web server doesn't provide either " .
				"REQUEST_URI, HTTP_X_ORIGINAL_URL or SCRIPT_NAME. Report details " .
				"of your web server configuration to https://phabricator.wikimedia.org/" );
		}

		// User-agents should not send a fragment with the URI, but
		// if they do, and the web server passes it on to us, we
		// need to strip it or we get false-positive redirect loops
		// or weird output URLs
		$hash = strpos( $base, '#' );
		if ( $hash !== false ) {
			$base = substr( $base, 0, $hash );
		}

		if ( $base[0] == '/' ) {
			// More than one slash will look like it is protocol relative
			return preg_replace( '!^/+!', '/', $base );
		} else {
			// We may get paths with a host prepended; strip it.
			return preg_replace( '!^[^:]+://[^/]+/+!', '/', $base );
		}
	}

	/**
	 * Return the path and query string portion of the request URI.
	 * This will be suitable for use as a relative link in HTML output.
	 *
	 * @throws MWException
	 * @return string
	 */
	public function getRequestURL() : string {
		$this->requestUrl = $this->requestUrl ?? self::getGlobalRequestURL();
		return $this->requestUrl;
	}

	/**
	 * Return the PATH_INFO or the path portion of the request URI.
	 *
	 * @throws MWException
	 * @return string
	 */
	public function getRequestPath() : string {
		global $wgScript;
		$this->requestPath = null
			?? self::getGlobalRequestPathInfo()
			?? self::parseURLPath( $this->getRequestURL(), $wgScript )
			?? '';
		return $this->requestPath;
	}

	/**
	 * Return the request path if failed to parse.
	 *
	 * @return ?string
	 */
	public function getBadPath() : ?string {
		return $this->badPath;
	}

	/**
	 * Return the request URI with the canonical service and hostname, path,
	 * and query string. This will be suitable for use as an absolute link
	 * in HTML or other output.
	 *
	 * If $wgServer is protocol-relative, this will return a fully
	 * qualified URL with the protocol of this request object.
	 *
	 * @return string
	 */
	public function getFullRequestURL() {
		// Pass an explicit PROTO constant instead of PROTO_CURRENT so that we
		// do not rely on state from the global $wgRequest object (which it would,
		// via wfGetServerUrl/wfExpandUrl/$wgRequest->protocol).
		if ( $this->getProtocol() === 'http' ) {
			return wfGetServerUrl( PROTO_HTTP ) . $this->getRequestURL();
		} else {
			return wfGetServerUrl( PROTO_HTTPS ) . $this->getRequestURL();
		}
	}

	/**
	 * Return the PATH_INFO part of the request (path after '.../index.php/') if enabled and present.
	 *
	 * @return ?string
	 */
	public static function getGlobalRequestPathInfo() : ?string {
		global $wgUsePathInfo;
		if ( $wgUsePathInfo ) {
			// Mangled PATH_INFO, when ORIG_PATH_INFO is set:
			// https://bugs.php.net/bug.php?id=31892
			// Also reported when ini_get('cgi.fix_pathinfo')==false
			return $_SERVER['ORIG_PATH_INFO'] ?? $_SERVER['PATH_INFO'] ?? null;
		}
		return null;
	}

	/**
	 * Return the path portion of an URL between the server and the query,
	 * or the PATH_INFO part if the URL begins with the script file.
	 *
	 * @param string $url to parse.
	 * @param ?string $scriptPrefix to remove from PATH_INFO style URL, ex. '/w/index.php'
	 * @return ?string The path portion.
	 */
	public static function parseURLPath( string $url, ?string $scriptPrefix = null ) : ?string {
		$path = parse_url( $url, PHP_URL_PATH );
		$pi = null;
		$scriptPath = $_SERVER['SCRIPT_NAME'] ?? null;
		if ( $scriptPath ) {
			$pi = wfRemovePrefix( $path, $scriptPath );
			if ( substr( $pi, 0, 1 ) !== '/' ) {
				$pi = null;
			}
		}
		if ( $pi === null && $scriptPrefix && $scriptPrefix !== $scriptPath ) {
			$pi = wfRemovePrefix( $path, $scriptPrefix );
			if ( substr( $pi, 0, 1 ) !== '/' ) {
				$pi = null;
			}
		}
		return $pi ?? $path;
	}

	/**
	 * @param string $key
	 * @param string $value
	 * @return string
	 */
	public function appendQueryValue( $key, $value ) {
		return $this->appendQueryArray( [ $key => $value ] );
	}

	/**
	 * Appends or replaces value of query variables.
	 *
	 * @param array $array Array of values to replace/add to query
	 * @return string
	 */
	public function appendQueryArray( $array ) {
		$newquery = $this->getQueryValues();
		unset( $newquery['title'] );
		$newquery = array_merge( $newquery, $array );

		return wfArrayToCgi( $newquery );
	}

	/**
	 * Check for limit and offset parameters on the input, and return sensible
	 * defaults if not given. The limit must be positive and is capped at 5000.
	 * Offset must be positive but is not capped.
	 *
	 * @param User $user User to get option for
	 * @param int $deflimit Limit to use if no input and the user hasn't set the option.
	 * @param string $optionname To specify an option other than rclimit to pull from.
	 * @return int[] First element is limit, second is offset
	 */
	public function getLimitOffsetForUser( User $user, $deflimit = 50, $optionname = 'rclimit' ) {
		$limit = $this->getInt( 'limit', 0 );
		if ( $limit < 0 ) {
			$limit = 0;
		}
		if ( ( $limit == 0 ) && ( $optionname != '' ) ) {
			$limit = $user->getIntOption( $optionname );
		}
		if ( $limit <= 0 ) {
			$limit = $deflimit;
		}
		if ( $limit > 5000 ) {
			$limit = 5000; # We have *some* limits...
		}

		$offset = $this->getInt( 'offset', 0 );
		if ( $offset < 0 ) {
			$offset = 0;
		}

		return [ $limit, $offset ];
	}

	/**
	 * Return the path to the temporary file where PHP has stored the upload.
	 *
	 * @param string $key
	 * @return string|null String or null if no such file.
	 */
	public function getFileTempname( $key ) {
		$file = new WebRequestUpload( $this, $key );
		return $file->getTempName();
	}

	/**
	 * Return the upload error or 0
	 *
	 * @param string $key
	 * @return int
	 */
	public function getUploadError( $key ) {
		$file = new WebRequestUpload( $this, $key );
		return $file->getError();
	}

	/**
	 * Return the original filename of the uploaded file, as reported by
	 * the submitting user agent. HTML-style character entities are
	 * interpreted and normalized to Unicode normalization form C, in part
	 * to deal with weird input from Safari with non-ASCII filenames.
	 *
	 * Other than this the name is not verified for being a safe filename.
	 *
	 * @param string $key
	 * @return string|null String or null if no such file.
	 */
	public function getFileName( $key ) {
		$file = new WebRequestUpload( $this, $key );
		return $file->getName();
	}

	/**
	 * Return a WebRequestUpload object corresponding to the key
	 *
	 * @param string $key
	 * @return WebRequestUpload
	 */
	public function getUpload( $key ) {
		return new WebRequestUpload( $this, $key );
	}

	/**
	 * Return a handle to WebResponse style object, for setting cookies,
	 * headers and other stuff, for Request being worked on.
	 *
	 * @return WebResponse
	 */
	public function response() {
		/* Lazy initialization of response object for this request */
		if ( !is_object( $this->response ) ) {
			$class = ( $this instanceof FauxRequest ) ? FauxResponse::class : WebResponse::class;
			$this->response = new $class();
		}
		return $this->response;
	}

	/**
	 * Initialise the header list
	 */
	protected function initHeaders() {
		if ( count( $this->headers ) ) {
			return;
		}

		$this->headers = array_change_key_case( getallheaders(), CASE_UPPER );
	}

	/**
	 * Get an array containing all request headers
	 *
	 * @return string[] Mapping header name to its value
	 */
	public function getAllHeaders() {
		$this->initHeaders();
		return $this->headers;
	}

	/**
	 * Get a request header, or false if it isn't set.
	 *
	 * @param string $name Case-insensitive header name
	 * @param int $flags Bitwise combination of:
	 *   WebRequest::GETHEADER_LIST  Treat the header as a comma-separated list
	 *                               of values, as described in RFC 2616 § 4.2.
	 *                               (since 1.26).
	 * @return string|string[]|false False if header is unset; otherwise the
	 *  header value(s) as either a string (the default) or an array, if
	 *  WebRequest::GETHEADER_LIST flag was set.
	 */
	public function getHeader( $name, $flags = 0 ) {
		$this->initHeaders();
		$name = strtoupper( $name );
		if ( !isset( $this->headers[$name] ) ) {
			return false;
		}
		$value = $this->headers[$name];
		if ( $flags & self::GETHEADER_LIST ) {
			$value = array_map( 'trim', explode( ',', $value ) );
		}
		return $value;
	}

	/**
	 * Get data from the session
	 *
	 * @note Prefer $this->getSession() instead if making multiple calls.
	 * @param string $key Name of key in the session
	 * @return mixed
	 */
	public function getSessionData( $key ) {
		return $this->getSession()->get( $key );
	}

	/**
	 * @note Prefer $this->getSession() instead if making multiple calls.
	 * @param string $key Name of key in the session
	 * @param mixed $data
	 */
	public function setSessionData( $key, $data ) {
		$this->getSession()->set( $key, $data );
	}

	/**
	 * This function formerly did a security check to prevent an XSS
	 * vulnerability in IE6, as documented in T30235. Since IE6 support has
	 * been dropped, this function now returns true unconditionally.
	 *
	 * @deprecated since 1.35
	 * @param array $extList
	 * @return bool
	 */
	public function checkUrlExtension( $extList = [] ) {
		wfDeprecated( __METHOD__, '1.35' );
		return true;
	}

	/**
	 * Parse the Accept-Language header sent by the client into an array
	 *
	 * @return array [ languageCode => q-value ] sorted by q-value in
	 *   descending order then appearing time in the header in ascending order.
	 * May contain the "language" '*', which applies to languages other than those explicitly listed.
	 *
	 * This logic is aligned with RFC 7231 section 5 (previously RFC 2616 section 14),
	 * at <https://tools.ietf.org/html/rfc7231#section-5.3.5>.
	 *
	 * Earlier languages in the list are preferred as per the RFC 23282 extension to HTTP/1.1,
	 * at <https://tools.ietf.org/html/rfc3282>.
	 */
	public function getAcceptLang() {
		// Modified version of code found at
		// http://www.thefutureoftheweb.com/blog/use-accept-language-header
		$acceptLang = $this->getHeader( 'Accept-Language' );
		if ( !$acceptLang ) {
			return [];
		}

		// Return the language codes in lower case
		$acceptLang = strtolower( $acceptLang );

		// Break up string into pieces (languages and q factors)
		if ( !preg_match_all(
			'/
				# a language code or a star is required
				([a-z]{1,8}(?:-[a-z]{1,8})*|\*)
				# from here everything is optional
				\s*
				(?:
					# this accepts only numbers in the range ;q=0.000 to ;q=1.000
					;\s*q\s*=\s*
					(1(?:\.0{0,3})?|0(?:\.\d{0,3})?)?
				)?
			/x',
			$acceptLang,
			$matches,
			PREG_SET_ORDER
		) ) {
			return [];
		}

		// Create a list like "en" => 0.8
		$langs = [];
		foreach ( $matches as $match ) {
			$languageCode = $match[1];
			// When not present, the default value is 1
			$qValue = (float)( $match[2] ?? 1.0 );
			if ( $qValue ) {
				$langs[$languageCode] = $qValue;
			}
		}

		// Sort list by qValue
		arsort( $langs, SORT_NUMERIC );
		return $langs;
	}

	/**
	 * Fetch the raw IP from the request
	 *
	 * @since 1.19
	 *
	 * @throws MWException
	 * @return string|null
	 */
	protected function getRawIP() {
		if ( !isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}

		if ( is_array( $_SERVER['REMOTE_ADDR'] ) || strpos( $_SERVER['REMOTE_ADDR'], ',' ) !== false ) {
			throw new MWException( __METHOD__
				. " : Could not determine the remote IP address due to multiple values." );
		} else {
			$ipchain = $_SERVER['REMOTE_ADDR'];
		}

		return IPUtils::canonicalize( $ipchain );
	}

	/**
	 * Work out the IP address based on various globals
	 * For trusted proxies, use the XFF client IP (first of the chain)
	 *
	 * @since 1.19
	 *
	 * @throws MWException
	 * @return string
	 */
	public function getIP() {
		global $wgUsePrivateIPs;

		# Return cached result
		if ( $this->ip !== null ) {
			return $this->ip;
		}

		# collect the originating ips
		$ip = $this->getRawIP();
		if ( !$ip ) {
			throw new MWException( 'Unable to determine IP.' );
		}

		# Append XFF
		$forwardedFor = $this->getHeader( 'X-Forwarded-For' );
		if ( $forwardedFor !== false ) {
			$proxyLookup = MediaWikiServices::getInstance()->getProxyLookup();
			$isConfigured = $proxyLookup->isConfiguredProxy( $ip );
			$ipchain = array_map( 'trim', explode( ',', $forwardedFor ) );
			$ipchain = array_reverse( $ipchain );
			array_unshift( $ipchain, $ip );

			# Step through XFF list and find the last address in the list which is a
			# trusted server. Set $ip to the IP address given by that trusted server,
			# unless the address is not sensible (e.g. private). However, prefer private
			# IP addresses over proxy servers controlled by this site (more sensible).
			# Note that some XFF values might be "unknown" with Squid/Varnish.
			foreach ( $ipchain as $i => $curIP ) {
				$curIP = IPUtils::sanitizeIP(
					IPUtils::canonicalize(
						self::canonicalizeIPv6LoopbackAddress( $curIP )
					)
				);
				if ( !$curIP || !isset( $ipchain[$i + 1] ) || $ipchain[$i + 1] === 'unknown'
					|| !$proxyLookup->isTrustedProxy( $curIP )
				) {
					break; // IP is not valid/trusted or does not point to anything
				}
				if (
					IPUtils::isPublic( $ipchain[$i + 1] ) ||
					$wgUsePrivateIPs ||
					$proxyLookup->isConfiguredProxy( $curIP ) // T50919; treat IP as sane
				) {
					$nextIP = $ipchain[$i + 1];

					// Follow the next IP according to the proxy
					$nextIP = IPUtils::canonicalize(
						self::canonicalizeIPv6LoopbackAddress( $nextIP )
					);
					if ( !$nextIP && $isConfigured ) {
						// We have not yet made it past CDN/proxy servers of this site,
						// so either they are misconfigured or there is some IP spoofing.
						throw new MWException( "Invalid IP given in XFF '$forwardedFor'." );
					}
					$ip = $nextIP;

					// keep traversing the chain
					continue;
				}
				break;
			}
		}

		# Allow extensions to improve our guess
		Hooks::runner()->onGetIP( $ip );

		if ( !$ip ) {
			throw new MWException( "Unable to determine IP." );
		}

		$this->ip = $ip;
		return $ip;
	}

	/**
	 * Converts ::1 (IPv6 loopback address) to 127.0.0.1 (IPv4 loopback address);
	 * assists in matching trusted proxies.
	 *
	 * @param string $ip
	 * @return string either '127.0.0.1' or $ip
	 * @since 1.36
	 */
	public static function canonicalizeIPv6LoopbackAddress( $ip ) {
		// Code moved from IPUtils library. See T248237#6614927
		$m = [];
		if ( preg_match( '/^0*' . IPUtils::RE_IPV6_GAP . '1$/', $ip, $m ) ) {
			return '127.0.0.1';
		}
		return $ip;
	}

	/**
	 * @param string $ip
	 * @return void
	 * @since 1.21
	 */
	public function setIP( $ip ) {
		$this->ip = $ip;
	}

	/**
	 * Check if this request uses a "safe" HTTP method
	 *
	 * Safe methods are verbs (e.g. GET/HEAD/OPTIONS) used for obtaining content. Such requests
	 * are not expected to mutate content, especially in ways attributable to the client. Verbs
	 * like POST and PUT are typical of non-safe requests which often change content.
	 *
	 * @return bool
	 * @see https://tools.ietf.org/html/rfc7231#section-4.2.1
	 * @see https://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html
	 * @since 1.28
	 */
	public function hasSafeMethod() {
		if ( !isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return false; // CLI mode
		}

		return in_array( $_SERVER['REQUEST_METHOD'], [ 'GET', 'HEAD', 'OPTIONS', 'TRACE' ] );
	}

	/**
	 * Whether this request should be identified as being "safe"
	 *
	 * This means that the client is not requesting any state changes and that database writes
	 * are not inherently required. Ideally, no visible updates would happen at all. If they
	 * must, then they should not be publicly attributed to the end user.
	 *
	 * In more detail:
	 *   - Cache populations and refreshes MAY occur.
	 *   - Private user session updates and private server logging MAY occur.
	 *   - Updates to private viewing activity data MAY occur via DeferredUpdates.
	 *   - Other updates SHOULD NOT occur (e.g. modifying content assets).
	 *
	 * @return bool
	 * @see https://tools.ietf.org/html/rfc7231#section-4.2.1
	 * @see https://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html
	 * @since 1.28
	 */
	public function isSafeRequest() {
		if ( $this->markedAsSafe && $this->wasPosted() ) {
			return true; // marked as a "safe" POST
		}

		return $this->hasSafeMethod();
	}

	/**
	 * Mark this request as identified as being nullipotent even if it is a POST request
	 *
	 * POST requests are often used due to the need for a client payload, even if the request
	 * is otherwise equivalent to a "safe method" request.
	 *
	 * @see https://tools.ietf.org/html/rfc7231#section-4.2.1
	 * @see https://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html
	 * @since 1.28
	 */
	public function markAsSafeRequest() {
		$this->markedAsSafe = true;
	}
}
