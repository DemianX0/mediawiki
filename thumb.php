<?php
/**
 * The web entry point for retrieving media thumbnails, created by a MediaHandler
 * subclass or proxy request if FileRepo::getThumbProxyUrl is configured.
 *
 * This script may also resize an image on-demand, if it isn't found in the
 * configured FileBackend storage.
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
 * @ingroup Media
 */

define( 'MW_NO_OUTPUT_COMPRESSION', 1 );
// T241340: thumb.php is included by thumb_handler.php which already defined
// MW_ENTRY_POINT to 'thumb_handler'
if ( !defined( 'MW_ENTRY_POINT' ) ) {
	define( 'MW_ENTRY_POINT', 'thumb' );
}
require __DIR__ . '/includes/WebStart.php';

MediaWiki\EntryPoint\ThumbLoaderEntryPoint::main();
