<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWiki\Http\HttpRequestFactory;
use Status;
use StatusValue;
use Wikimedia\TestingAccessWrapper;

/**
 * @coversNothing smoke tests
 */
class WikimediaPageViewServiceSmokeTest extends \PHPUnit\Framework\TestCase {
	protected function getService() {
		global $wgPageViewInfoWikimediaEndpoint;
		return new WikimediaPageViewService(
			$this->createMock( HttpRequestFactory::class ),
			$wgPageViewInfoWikimediaEndpoint,
			[ 'project' => 'en.wikipedia.org' ],
			3
		);
	}

	public function testGetPageData() {
		$service = $this->getService();
		$randomTitle = ucfirst( \MWCryptRand::generateHex( 32 ) );
		$titles = [ 'Main_Page', 'Mycotoxin', $randomTitle ];
		$status = $service->getPageData( array_map( static function ( $t ) {
			return \Title::newFromText( $t );
		}, $titles ), 5 );
		if ( !$status->isOK() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$data = $status->getValue();
		$this->assertIsArray( $data, $this->debug( $data, $status ) );
		$this->assertCount( 3, $data, $this->debug( $data, $status ) );
		$day = gmdate( 'Y-m-d', time() - 3 * 24 * 3600 );
		foreach ( $titles as $title ) {
			$this->assertArrayHasKey( $title, $data, $this->debug( $data, $status ) );
			$this->assertIsArray( $data[$title], $this->debug( $data, $status ) );
			$this->assertCount( 5, $data[$title], $this->debug( $data, $status ) );
			$this->assertArrayHasKey( $day, $data[$title], $this->debug( $data, $status ) );
		}
		$this->assertIsInt( $data['Main_Page'][$day], $this->debug( $data, $status ) );
		$this->assertGreaterThan( 1000, $data['Main_Page'][$day], $this->debug( $data, $status ) );
		$this->assertIsInt( $data['Mycotoxin'][$day], $this->debug( $data, $status ) );
		$this->assertLessThan( 1000, $data['Mycotoxin'][$day], $this->debug( $data, $status ) );
		$this->assertNull( $data[$randomTitle][$day], $this->debug( $data, $status ) );
	}

	public function testGetSiteData() {
		$service = $this->getService();
		$status = $service->getSiteData( 5 );
		if ( !$status->isOK() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$data = $status->getValue();
		$this->assertIsArray( $data, $this->debug( $data, $status ) );
		$this->assertCount( 5, $data, $this->debug( $data, $status ) );
		$day = gmdate( 'Y-m-d', time() - 3 * 24 * 3600 );
		$this->assertArrayHasKey( $day, $data, $this->debug( $data, $status ) );
		$this->assertIsInt( $data[$day], $this->debug( $data, $status ) );
		$this->assertGreaterThan( 100000, $data[$day], $this->debug( $data, $status ) );
	}

	public function testGetSiteData_unique() {
		$service = $this->getService();
		$status = $service->getSiteData( 5, PageViewService::METRIC_UNIQUE );
		if ( !$status->isOK() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$data = $status->getValue();
		$this->assertIsArray( $data, $this->debug( $data, $status ) );
		$this->assertCount( 5, $data, $this->debug( $data, $status ) );
		$day = gmdate( 'Y-m-d', time() - 3 * 24 * 3600 );
		$this->assertArrayHasKey( $day, $data, $this->debug( $data, $status ) );
		$this->assertIsInt( $data[$day], $this->debug( $data, $status ) );
		$this->assertGreaterThan( 100000, $data[$day], $this->debug( $data, $status ) );
	}

	public function testGetTopPages() {
		$service = $this->getService();
		$status = $service->getTopPages();
		if ( !$status->isOK() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$data = $status->getValue();
		$this->assertIsArray( $data, $this->debug( $data, $status ) );
		$this->assertArrayHasKey( 'Main_Page', $data, $this->debug( $data, $status ) );
		$this->assertSame( 'Main_Page', key( $data ), $this->debug( $data, $status ) );
		$this->assertGreaterThan( 100000, $data['Main_Page'], $this->debug( $data, $status ) );
	}

	public function testRequestError() {
		$service = $this->getService();
		$wrapper = TestingAccessWrapper::newFromObject( $service );
		$wrapper->access = 'fail';
		$logger = new \TestLogger( true, null, true );
		$service->setLogger( $logger );
		$status = $service->getPageData( [ \Title::newFromText( 'Main_Page' ) ], 5 );
		$this->assertFalse( $status->isOK() );
		$logBuffer = $logger->getBuffer();
		$this->assertNotEmpty( $logBuffer );
		$this->assertArrayHasKey( 'apierror_type', $logBuffer[0][2] );
		$this->assertSame( 'https://mediawiki.org/wiki/HyperSwitch/errors/bad_request',
			$logBuffer[0][2]['apierror_type'] );
	}

	/**
	 * @param array $data
	 * @param StatusValue $status
	 * @return string
	 */
	protected function debug( $data, $status ) {
		$debug = 'Assertion failed for data:' . PHP_EOL . var_export( $data, true );
		if ( !$status->isGood() ) {
			$debug .= PHP_EOL . 'Status:' . PHP_EOL . Status::wrap( $status )->getWikiText();
		}
		return $debug;
	}
}
