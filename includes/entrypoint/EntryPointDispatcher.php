<?php
/**
 * The single web entry point multiplexing specific entry points.
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
 * @ingroup entrypoint
 */

namespace MediaWiki\EntryPoint;

/**
 * Common web entry point
 */
class EntryPointDispatcher {

	public static $entryPointClasses = [
		'index' => '\MediaWiki\EntryPoint\WebPageEntryPoint',
		'load' => '\MediaWiki\EntryPoint\ResourceLoaderEntryPoint',
		'api' => '\MediaWiki\EntryPoint\ApiEntryPoint',
		'rest' => '\MediaWiki\Rest\EntryPoint',
		'thumb' => '\MediaWiki\EntryPoint\ThumbLoaderEntryPoint',
		'thumb_handler' => '\MediaWiki\EntryPoint\ThumbLoaderEntryPoint',
		'opensearch_desc' => '\MediaWiki\EntryPoint\OpenSearchEntryPoint',
		// NSFileRepo extension: 'img_auth' => extensions/NSFileRepo/nsfr_img_auth.php
	];

	/**
	 * Dispatch request to chosen entry point.
	 *
	 * @param string $entryPoint Name of the entry point to call, ex. 'index', 'load', 'api', 'rest'.
	 */
	public static function callEntryPoint( string $entryPoint ) {
		$class = self::$entryPointClasses[ $entryPoint ];
		$class::main();
	}
}
