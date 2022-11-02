<?php

namespace MediaWiki\Extension\PageViewInfo;

use HashBagOStuff;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\PageViewInfo\CachedPageViewService
 */
class CachedPageViewServiceTest extends TestCase {
	/** @var CachedPageViewService */
	protected $service;
	/** @var MockObject */
	protected $mock;

	protected function setUp(): void {
		parent::setUp();
		$cache = new HashBagOStuff();
		$this->mock = $this->createMock( PageViewService::class );
		$this->service = new CachedPageViewService( $this->mock, $cache );
		$this->service->setCachedDays( 2 );
	}

	/**
	 * @dataProvider provideSupports
	 */
	public function testSupports( $metric, $scope, $expectation ) {
		$this->mock->expects( $this->once() )
			->method( 'supports' )
			->willReturnCallback( static function ( $metric, $scope ) {
				return $metric === PageViewService::METRIC_VIEW ||
					   $scope === PageViewService::SCOPE_SITE;
			} );
		$this->assertSame( $expectation, $this->service->supports( $metric, $scope ) );
	}

	public function provideSupports() {
		return [
			[ PageViewService::METRIC_VIEW, PageViewService::SCOPE_ARTICLE, true ],
			[ PageViewService::METRIC_UNIQUE, PageViewService::SCOPE_SITE, true ],
			[ PageViewService::METRIC_UNIQUE, PageViewService::SCOPE_ARTICLE, false ],
		];
	}

	public function testGetCacheExpiry() {
		$this->mock->expects( $this->once() )
			->method( 'getCacheExpiry' )
			->willReturn( 1000 );
		$expiry = $this->service->getCacheExpiry( PageViewService::METRIC_VIEW,
			PageViewService::SCOPE_ARTICLE );
		$this->assertGreaterThanOrEqual( 1000, $expiry );
		$this->assertLessThanOrEqual( 1600, $expiry );
	}

	public function testGetPageData() {
		$expectedTitles = [];
		$this->service->setCachedDays( 2 );
		$this->mock->method( 'getCacheExpiry' )
			->willReturn( 1000 );
		$this->mock->method( 'getPageData' )
			->with( $this->anything(), $this->anything(), $this->logicalOr(
				PageViewService::METRIC_VIEW, PageViewService::METRIC_UNIQUE ) )
			->willReturnCallback( function ( $titles, $days, $metric ) use ( &$expectedTitles ) {
				$metric = ( $metric === PageViewService::METRIC_VIEW ) + 1;
				$titles = array_fill_keys( array_map( static function ( \Title $t ) {
					return $t->getPrefixedDBkey();
				}, $titles ), null );
				$this->assertSame( $expectedTitles, array_keys( $titles ) );
				// 'A' => 1, 'B' => 2, ...
				$pages = array_combine( array_map( static function ( $n ) {
					return chr( ord( 'A' ) + $n - 1 );
				}, range( 1, 20 ) ), range( 1, 20 ) );
				// simulate a page-per-query limit of 3
				$base = array_slice( array_intersect_key( $pages, $titles ), 0, 3 );
				$perDay = array_slice( [
					'2000-01-01' => $metric * 1,
					'2000-01-02' => $metric * 2,
					'2000-01-03' => $metric * 3,
					'2000-01-04' => $metric * 4,
					'2000-01-05' => $metric * 5,
				], -$days );
				// add some errors
				$data = array_map( static function ( $multiplier ) use ( $perDay ) {
					if ( $multiplier > 10 && $multiplier % 2 ) {
						return null;
					}
					return array_map( static function ( $count ) use ( $multiplier ) {
						return $count * $multiplier;
					}, $perDay );
				}, $base );
				$status = StatusValue::newGood();
				foreach ( $data as $title => $titleData ) {
					if ( $titleData === null ) {
						$status->error( '500 #' . $title );
					}
				}
				$status->success = array_map( static function ( $v ) {
					return $v !== null;
				}, $data );
				$status->successCount = count( array_filter( $status->success ) );
				$status->failCount = count( $status->success ) - $status->successCount;
				$status->setResult( $status->successCount, array_map( static function ( $titleData ) use ( $perDay ) {
					return $titleData ?: array_fill_keys( array_keys( $perDay ), null );
				}, $data ) );
				return $status;
			} );
		$makeTitles = static function ( $titles ) {
			return array_map( static function ( $t ) {
				return \Title::newFromText( $t );
			}, $titles );
		};

		$expectedTitles = [ 'A', 'B' ];
		$status = $this->service->getPageData( $makeTitles( [ 'A', 'B' ] ), 1,
			PageViewService::METRIC_VIEW );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [
			'A' => [ '2000-01-05' => 10 ],
			'B' => [ '2000-01-05' => 20 ],
		], $status->getValue() );
		$this->assertSame( [ 'A' => true, 'B' => true ], $status->success );
		$this->assertSame( 2, $status->successCount );
		$this->assertSame( 0, $status->failCount );

		// second call should not trigger a new call to the wrapped service, regardless of $days
		// days beyond setCachedDays() should be null
		$expectedTitles = [];
		$status = $this->service->getPageData( $makeTitles( [ 'A', 'B' ] ), 3,
			PageViewService::METRIC_VIEW );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [
			'A' => [ '2000-01-03' => null, '2000-01-04' => 8, '2000-01-05' => 10 ],
			'B' => [ '2000-01-03' => null, '2000-01-04' => 16, '2000-01-05' => 20 ],
		], $status->getValue() );
		$this->assertSame( [ 'A' => true, 'B' => true ], $status->success );
		$this->assertSame( 2, $status->successCount );
		$this->assertSame( 0, $status->failCount );

		// caching should not mix up metrics
		$expectedTitles = [ 'A', 'B' ];
		$status = $this->service->getPageData( $makeTitles( [ 'A', 'B' ] ), 1,
			PageViewService::METRIC_UNIQUE );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [
			'A' => [ '2000-01-05' => 5 ],
			'B' => [ '2000-01-05' => 10 ],
		], $status->getValue() );
		$this->assertSame( [ 'A' => true, 'B' => true ], $status->success );
		$this->assertSame( 2, $status->successCount );
		$this->assertSame( 0, $status->failCount );

		// titles should be cached individually
		$expectedTitles = [ 'C' ];
		$status = $this->service->getPageData( $makeTitles( [ 'A', 'C' ] ), 1,
			PageViewService::METRIC_VIEW );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [
			'A' => [ '2000-01-05' => 10 ],
			'C' => [ '2000-01-05' => 30 ],
		], $status->getValue() );
		$this->assertSame( [ 'A' => true, 'C' => true ], $status->success );
		$this->assertSame( 2, $status->successCount );
		$this->assertSame( 0, $status->failCount );

		// needs to handle the wrapped service returning less pages than asked
		$expectedTitles = [ 'D', 'E', 'F', 'G' ];
		$status = $this->service->getPageData( $makeTitles( [ 'A', 'B', 'C', 'D', 'E', 'F', 'G' ] ), 1,
			PageViewService::METRIC_VIEW );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [
			'A' => [ '2000-01-05' => 10 ],
			'B' => [ '2000-01-05' => 20 ],
			'C' => [ '2000-01-05' => 30 ],
			'D' => [ '2000-01-05' => 40 ],
			'E' => [ '2000-01-05' => 50 ],
			'F' => [ '2000-01-05' => 60 ],
		], $status->getValue() );
		$this->assertSame( [ 'A' => true, 'B' => true, 'C' => true, 'D' => true, 'E' => true,
			'F' => true ], $status->success );
		$this->assertSame( 6, $status->successCount );
		$this->assertSame( 0, $status->failCount );

		// some errors
		$expectedTitles = [ 'K', 'L' ];
		$status = $this->service->getPageData( $makeTitles( [ 'A', 'K', 'L' ] ), 1,
			PageViewService::METRIC_VIEW );
		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $status->isGood() );
		$this->assertSame( [
			'A' => [ '2000-01-05' => 10 ],
			'K' => [ '2000-01-05' => null ],
			'L' => [ '2000-01-05' => 120 ],
		], $status->getValue() );
		$this->assertTrue( $status->hasMessage( 'pvi-cached-error-title' ) );
		$this->assertFalse( $status->hasMessage( '500 #K' ) );
		$this->assertSame( [ 'A' => true, 'K' => false, 'L' => true ], $status->success );
		$this->assertSame( 2, $status->successCount );
		$this->assertSame( 1, $status->failCount );

		// cached error
		$expectedTitles = [ 'N' ];
		$status = $this->service->getPageData( $makeTitles( [ 'A', 'K', 'L', 'N' ] ), 1,
			PageViewService::METRIC_VIEW );
		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $status->isGood() );
		$this->assertSame( [
			'A' => [ '2000-01-05' => 10 ],
			'K' => [ '2000-01-05' => null ],
			'L' => [ '2000-01-05' => 120 ],
			'N' => [ '2000-01-05' => 140 ],
		], $status->getValue() );
		$this->assertTrue( $status->hasMessage( 'pvi-cached-error-title' ) );
		$this->assertSame( [ 'A' => true, 'K' => false, 'L' => true, 'N' => true ], $status->success );
		$this->assertSame( 3, $status->successCount );
		$this->assertSame( 1, $status->failCount );

		// all errors
		$expectedTitles = [ 'M' ];
		$status = $this->service->getPageData( $makeTitles( [ 'K', 'M' ] ), 1,
			PageViewService::METRIC_VIEW );
		$this->assertFalse( $status->isOK() );
		$this->assertFalse( $status->isGood() );
		$this->assertSame( [
			'K' => [ '2000-01-05' => null ],
			'M' => [ '2000-01-05' => null ],
		], $status->getValue() );
		$this->assertTrue( $status->hasMessage( 'pvi-cached-error-title' ) );
		$this->assertFalse( $status->hasMessage( '500 #M' ) );
		$this->assertSame( [ 'K' => false, 'M' => false ], $status->success );
		$this->assertSame( 0, $status->successCount );
		$this->assertSame( 2, $status->failCount );
	}

	public function testGetSiteData() {
		$cached = false;
		$this->service->setCachedDays( 2 );
		$this->mock->method( 'getCacheExpiry' )
			->willReturn( 1000 );
		$this->mock->expects( $this->exactly( 2 ) )
			->method( 'getSiteData' )
			->with( $this->anything(), $this->logicalOr( PageViewService::METRIC_VIEW,
				PageViewService::METRIC_UNIQUE ) )
			->willReturnCallback( function ( $days, $metric ) use ( &$cached ) {
				if ( $cached ) {
					$this->fail( 'should have been cached' );
				}
				$metric = ( $metric === PageViewService::METRIC_VIEW ) + 1;
				return StatusValue::newGood( array_slice( [
					'2000-01-01' => $metric * 1,
					'2000-01-02' => $metric * 2,
					'2000-01-03' => $metric * 3,
					'2000-01-04' => $metric * 4,
					'2000-01-05' => $metric * 5,
				], -$days ) );
			} );
		$status = $this->service->getSiteData( 1, PageViewService::METRIC_VIEW );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [ '2000-01-05' => 10 ], $status->getValue() );

		// second call should not trigger a new call to the wrapped service, regardless of $days
		// days beyond setCachedDays() should be null
		$cached = true;
		$status = $this->service->getSiteData( 3, PageViewService::METRIC_VIEW );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [ '2000-01-03' => null, '2000-01-04' => 8, '2000-01-05' => 10 ],
			$status->getValue() );

		// caching should not mix up metrics
		$cached = false;
		$status = $this->service->getSiteData( 1, PageViewService::METRIC_UNIQUE );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [ '2000-01-05' => 5 ], $status->getValue() );
	}

	public function testGetSiteData_error() {
		$cached = false;
		$this->service->setCachedDays( 2 );
		$this->mock->method( 'getCacheExpiry' )
			->willReturn( 1000 );
		$this->mock->expects( $this->exactly( 2 ) )
			->method( 'getSiteData' )
			->with( $this->anything(), $this->logicalOr( PageViewService::METRIC_VIEW,
				PageViewService::METRIC_UNIQUE ) )
			->willReturnCallback( function ( $days, $metric ) use ( &$cached ) {
				if ( $cached ) {
					$this->fail( 'should have been cached' );
				}
				return $metric === PageViewService::METRIC_VIEW ? StatusValue::newFatal( '500' )
					: StatusValue::newFatal( '500 #2' );
			} );
		$status = $this->service->getSiteData( 1, PageViewService::METRIC_VIEW );
		$this->assertFalse( $status->isOK() );
		$this->assertTrue( $status->hasMessage( '500' ) );
		$this->assertFalse( $status->hasMessage( 'pvi-cached-error' ) );

		// second call should not trigger a new call to the wrapped service, regardless of $days
		$cached = true;
		$status = $this->service->getSiteData( 3, PageViewService::METRIC_VIEW );
		$this->assertFalse( $status->isOK() );
		$this->assertTrue( $status->hasMessage( 'pvi-cached-error' ) );

		// caching should not mix up metrics
		$cached = false;
		$status = $this->service->getSiteData( 1, PageViewService::METRIC_UNIQUE );
		$this->assertFalse( $status->isOK() );
		$this->assertTrue( $status->hasMessage( '500 #2' ) );
		$this->assertFalse( $status->hasMessage( 'pvi-cached-error' ) );
	}

	public function testGetTopPages() {
		$cached = false;
		$this->mock->method( 'getCacheExpiry' )
			->willReturn( 1000 );
		$this->mock->expects( $this->exactly( 2 ) )
			->method( 'getTopPages' )
			->with( $this->logicalOr( PageViewService::METRIC_VIEW, PageViewService::METRIC_UNIQUE ) )
			->willReturnCallback( function ( $metric ) use ( &$cached ) {
				if ( $cached ) {
					$this->fail( 'should have been cached' );
				}
				switch ( $metric ) {
					case PageViewService::METRIC_VIEW:
						return StatusValue::newGood( [ 'A' => 100, 'B' => 10, 'C' => 1 ] );
					case PageViewService::METRIC_UNIQUE:
						return StatusValue::newGood( [ 'A' => 50, 'B' => 5, 'C' => 1 ] );
				}
			} );
		$status = $this->service->getTopPages( PageViewService::METRIC_VIEW );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [ 'A' => 100, 'B' => 10, 'C' => 1 ], $status->getValue() );

		// second call should not trigger a new call to the wrapped service
		$cached = true;
		$status = $this->service->getTopPages( PageViewService::METRIC_VIEW );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [ 'A' => 100, 'B' => 10, 'C' => 1 ], $status->getValue() );

		// caching should not mix up metrics
		$cached = false;
		$status = $this->service->getTopPages( PageViewService::METRIC_UNIQUE );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [ 'A' => 50, 'B' => 5, 'C' => 1 ], $status->getValue() );
	}

	public function testGetTopPages_error() {
		$cached = false;
		$this->mock->method( 'getCacheExpiry' )
			->willReturn( 1000 );
		$this->mock->expects( $this->exactly( 2 ) )
			->method( 'getTopPages' )
			->with( $this->logicalOr( PageViewService::METRIC_VIEW, PageViewService::METRIC_UNIQUE ) )
			->willReturnCallback( function ( $metric ) use ( &$cached ) {
				if ( $cached ) {
					$this->fail( 'should have been cached' );
				}
				switch ( $metric ) {
					case PageViewService::METRIC_VIEW:
						return StatusValue::newFatal( '500' );
					case PageViewService::METRIC_UNIQUE:
						return StatusValue::newFatal( '500 #2' );
				}
			} );
		$status = $this->service->getTopPages( PageViewService::METRIC_VIEW );
		$this->assertFalse( $status->isOK() );
		$this->assertTrue( $status->hasMessage( '500' ) );
		$this->assertFalse( $status->hasMessage( 'pvi-cached-error' ) );

		// second call should not trigger a new call to the wrapped service
		$cached = true;
		$status = $this->service->getTopPages( PageViewService::METRIC_VIEW );
		$this->assertFalse( $status->isOK() );
		$this->assertFalse( $status->hasMessage( '500' ) );
		$this->assertTrue( $status->hasMessage( 'pvi-cached-error' ) );

		// caching should not mix up metrics
		$cached = false;
		$status = $this->service->getTopPages( PageViewService::METRIC_UNIQUE );
		$this->assertFalse( $status->isOK() );
		$this->assertTrue( $status->hasMessage( '500 #2' ) );
		$this->assertFalse( $status->hasMessage( 'pvi-cached-error' ) );
	}
}
