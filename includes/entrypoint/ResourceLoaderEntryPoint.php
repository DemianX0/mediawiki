<?php
/**
 * The web entry point for ResourceLoader, which serves static CSS/JavaScript
 * via ResourceLoaderModule subclasses.
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
 * @ingroup ResourceLoader
 * @author Roan Kattouw
 * @author Trevor Parscal
 */

namespace MediaWiki\EntryPoint;

use MediaWiki;
use MediaWiki\MediaWikiServices;
use Profiler;
use ResourceLoaderContext;

class ResourceLoaderEntryPoint {

	public static function main() {
		global $wgRequest;
		if ( !defined( 'MW_NO_SESSION' ) ) {
			define( 'MW_NO_SESSION', 1 );
		}

		// Disable ChronologyProtector so that we don't wait for unrelated MediaWiki
		// writes when getting database connections for ResourceLoader. (T192611)
		MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->disableChronologyProtection();

		$resourceLoader = MediaWikiServices::getInstance()->getResourceLoader();
		$context = new ResourceLoaderContext( $resourceLoader, $wgRequest );
		MediaWikiServices::getInstance()->redefineService( 'MessageLocalizer', function () use ( $context ) {
			return $context;
		} );

		// Respond to ResourceLoader request
		$resourceLoader->respond( $context );

		// Append any visible profiling data in a manner appropriate for the Content-Type
		Profiler::instance()->setAllowOutput();
		Profiler::instance()->logDataPageOutputOnly();

		$mediawiki = new MediaWiki();
		$mediawiki->doPostOutputShutdown();
	}
}
