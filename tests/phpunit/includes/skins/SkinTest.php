<?php

use Psr\Container\ContainerInterface;
use Wikimedia\ObjectFactory;

class SkinTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers Skin::getDefaultModules
	 */
	public function testGetDefaultModules() {
		$skin = $this->getMockBuilder( Skin::class )
			->setMethods( [ 'outputPage' ] )
			->getMock();

		$modules = $skin->getDefaultModules();
		$this->assertTrue( isset( $modules['core'] ), 'core key is set by default' );
		$this->assertTrue( isset( $modules['styles'] ), 'style key is set by default' );
	}

	/**
	 * @covers Skin::getAllowedSkins
	 */
	public function testGetAllowedSkinsEmpty() {
		$skin = $this->getMockBuilder( Skin::class )
			->setMethods( [ 'outputPage' ] )
			->getMock();

		$factory = new SkinFactory(
			new ObjectFactory( $this->createMock( ContainerInterface::class ) ), []
		);

		$this->setService( 'SkinFactory', $factory );
		$this->setMwGlobals( 'wgSkipSkins', [] );
		$this->assertEquals( [], $skin->getAllowedSkins() );
	}

	/**
	 * @covers Skin::getAllowedSkins
	 */
	public function testGetAllowedSkins() {
		$skin = $this->getMockBuilder( Skin::class )
			->setMethods( [ 'outputPage' ] )
			->getMock();
		$noop = function () {
		};

		$sf = new SkinFactory(
			new ObjectFactory( $this->createMock( ContainerInterface::class ) ), []
		);
		$sf->register( 'foo', 'Foo', $noop );
		$sf->register( 'apioutput', 'ApiOutput', $noop );
		$sf->register( 'quux', 'Quux', $noop );
		$sf->register( 'fallback', 'Fallback', $noop );
		$sf->register( 'bar', 'Barbar', $noop );

		$this->setService( 'SkinFactory', $sf );
		$this->setMwGlobals( 'wgSkipSkins', [ 'quux' ] );

		$this->assertEquals(
			[ 'foo' => 'Foo', 'bar' => 'Barbar' ],
			$skin->getAllowedSkins()
		);
	}

	/**
	 * @covers Skin::isResponsive
	 *
	 * @dataProvider provideSkinResponsiveOptions
	 *
	 * @param array $options
	 * @param bool $expected
	 */
	public function testIsResponsive( array $options, bool $expected ) {
		$skin = new class( $options ) extends Skin {
			/**
			 * @inheritDoc
			 */
			public function outputPage() {
			}
		};

		$this->assertSame( $expected, $skin->isResponsive() );
	}

	public function provideSkinResponsiveOptions() : array {
		return [
			'responsive not set' => [ [], false ],
			'responsive false' => [ [ 'responsive' => false ], false ],
			'responsive true' => [ [ 'responsive' => true ], true ]
		];
	}

	/**
	 * @covers Skin::doEditSectionLink
	 */
	public function testDoEditSectionLink() {
		$skin = $this->getMockBuilder( Skin::class )
			->setMethods( [ 'outputPage', 'setupSkinUserCss' ] )
			->getMock();

		$title = Title::newFromText( 'TestPage' );
		$section = 'section1';
		$tooltip = 'tip';
		$language = Language::factory( 'en' );
		$anchor = 'test';

		$expected = '<span class="mw-editsection"><span class="mw-editsection-bracket">[</span>'
		. '<a href="/index.php?title=TestPage&amp;action=edit&amp;section=section1"'
		. ' title="Edit section: tip">edit</a><span class="mw-editsection-bracket">]</span>'
		. '</span>';
		$html = $skin->doEditSectionLink( $title, $section, $tooltip, $language, $anchor );
		$this->assertEquals( $expected, $html );

		$this->setMwGlobals( 'wgEnableSectionHeaderShare', true );
		$expected = '<span class="mw-editsection"><span class="mw-editsection-bracket">[</span>'
		. '<a href="/index.php?title=TestPage&amp;action=edit&amp;section=section1"'
		. ' title="Edit section: tip">edit</a><span class="mw-editsection-divider"> | </span>'
		. '<a href="/index.php/TestPage#test" class="mw-editsection-share"'
		. ' title="Share section: tip" data-mw-share-section="tip">share</a>'
		. '<span class="mw-editsection-bracket">]</span></span>';
		$html = $skin->doEditSectionLink( $title, $section, $tooltip, $language, $anchor );
		$this->assertEquals( $expected, $html );
	}
}
