<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWiki\Http\HttpRequestFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\PageViewInfo\WikimediaPageViewService
 */
class WikimediaPageViewServiceTest extends TestCase {
	/** @var array [ MockObject, callable ] */
	protected $calls = [];

	public function setUp(): void {
		parent::setUp();
		$this->calls = [];
	}

	protected function assertThrows( $class, callable $test ) {
		try {
			$test();
		} catch ( \Exception $e ) {
			$this->assertInstanceOf( $class, $e );
			return;
		}
		$this->fail( 'No exception was thrown, expected ' . $class );
	}

	protected function mockHttpRequestFactory() {
		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->method( 'create' )
			->willReturnCallback( function ( $url ) {
				if ( !$this->calls ) {
					$this->fail( 'Unexpected call!' );
				}
				list( $mock, $assertUrl ) = array_shift( $this->calls );
				if ( $assertUrl ) {
					$assertUrl( $url );
				}
				return $mock;
			} );
		return $httpRequestFactory;
	}

	/**
	 * Creates and returns a mock MWHttpRequest which will be used by the mocked HttpRequestFactory for the next call
	 * @param callable|null $assertUrl A callable that gets the URL
	 * @return MockObject
	 */
	protected function mockNextRequest( callable $assertUrl = null ) {
		$mock = $this->createMock( \MWHttpRequest::class );
		$this->calls[] = [ $mock, $assertUrl ];
		return $mock;
	}

	/**
	 * Changes the start/end dates
	 * @param WikimediaPageViewService $service
	 * @param string $end YYYY-MM-DD
	 */
	protected function mockDate( WikimediaPageViewService $service, $end ) {
		$wrapper = TestingAccessWrapper::newFromObject( $service );
		$wrapper->lastCompleteDay = strtotime( $end . 'T00:00Z' );
		$wrapper->range = null;
	}

	/**
	 * Imitate a no-data 404 error from the REST API
	 * @return string
	 */
	protected function get404ErrorJson() {
		return json_encode( [
			'type' => 'https://mediawiki.org/wiki/HyperSwitch/errors/not_found',
			'title' => 'Not found.',
			'method' => 'get',
			'detail' => 'The date(s) you used are valid, but we either do not have data for those date(s), '
				. 'or the project you asked for is not loaded yet. Please check '
				. 'https://wikimedia.org/api/rest_v1/?doc for more information.',
			'uri' => 'whatever, won\'t be used',
		] );
	}

	public function testConstructor() {
		$this->assertThrows( \InvalidArgumentException::class, function () {
			new WikimediaPageViewService(
				$this->createMock( HttpRequestFactory::class ),
				'null:',
				[],
				false
			);
		} );
		new WikimediaPageViewService(
			$this->createMock( HttpRequestFactory::class ),
			'null:',
			[ 'project' => 'http://example.com/' ],
			false
		);
	}

	public function testGetPageData() {
		$service = new WikimediaPageViewService(
			$this->mockHttpRequestFactory(),
			'http://endpoint.example.com/',
			[ 'project' => 'project.example.com' ],
			false
		);
		$this->mockDate( $service, '2000-01-05' );

		// valid request
		$mockFoo = $this->mockNextRequest( function ( $url ) {
			$this->assertSame( 'http://endpoint.example.com/metrics/pageviews/per-article/'
				. 'project.example.com/all-access/user/Foo/daily/20000101/20000105', $url );
		} );
		$mockBar = $this->mockNextRequest( function ( $url ) {
			$this->assertSame( 'http://endpoint.example.com/metrics/pageviews/per-article/'
				. 'project.example.com/all-access/user/Bar/daily/20000101/20000105', $url );
		} );
		foreach ( [ 'Foo' => $mockFoo, 'Bar' => $mockBar ] as $page => $mock ) {
			/** @var MockObject $mock */
			$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newGood() );
			$mock->method( 'getContent' )->willReturn( json_encode( [
				'items' => [
					[
						'project' => 'project.example.com',
						'article' => $page,
						'granularity' => 'daily',
						'timestamp' => '2000010100',
						'access' => 'all-access',
						'agent' => 'user',
						'views' => $page === 'Foo' ? 1000 : 500,
					],
					[
						'project' => 'project.example.com',
						'article' => $page,
						'granularity' => 'daily',
						'timestamp' => '2000010200',
						'access' => 'all-access',
						'agent' => 'user',
						'views' => $page === 'Foo' ? 100 : 50,
					],
					[
						'project' => 'project.example.com',
						'article' => $page,
						'granularity' => 'daily',
						'timestamp' => '2000010400',
						'access' => 'all-access',
						'agent' => 'user',
						'views' => $page === 'Foo' ? 10 : 5,
					],
				]
			] ) );
			$mock->method( 'getStatus' )->willReturn( 200 );
		}
		$status = $service->getPageData( [ \Title::newFromText( 'Foo' ),
			\Title::newFromText( 'Bar' ) ], 5 );
		if ( !$status->isGood() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'Foo' => [
				'2000-01-01' => 1000,
				'2000-01-02' => 100,
				'2000-01-03' => null,
				'2000-01-04' => 10,
				'2000-01-05' => null,
			],
			'Bar' => [
				'2000-01-01' => 500,
				'2000-01-02' => 50,
				'2000-01-03' => null,
				'2000-01-04' => 5,
				'2000-01-05' => null,
			],
		], $status->getValue() );
		$this->assertSame( [ 'Foo' => true, 'Bar' => true ], $status->success );
		$this->assertSame( 2, $status->successCount );
		$this->assertSame( 0, $status->failCount );

		$this->mockDate( $service, '2000-01-01' );
		// valid, 404 and error, combined
		$this->calls = [];
		$mockA = $this->mockNextRequest();
		$mockA->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newGood() );
		$mockA->method( 'getContent' )->willReturn( json_encode( [
			'items' => [
				[
					'project' => 'project.example.com',
					'article' => 'A',
					'granularity' => 'daily',
					'timestamp' => '2000010100',
					'access' => 'all-access',
					'agent' => 'user',
					'views' => 1,
				],
			],
		] ) );
		$mockA->method( 'getStatus' )->willReturn( 200 );
		$mockB = $this->mockNextRequest();
		$mockB->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newFatal( '404' ) );
		$mockB->method( 'getContent' )->willReturn( $this->get404ErrorJson() );
		$mockB->method( 'getStatus' )->willReturn( 404 );
		$mockC = $this->mockNextRequest();
		$mockC->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newFatal( '500' ) );
		$mockC->method( 'getContent' )->willReturn( '' );
		$mockC->method( 'getStatus' )->willReturn( 500 );
		$status = $service->getPageData( [ \Title::newFromText( 'A' ),
			\Title::newFromText( 'B' ), \Title::newFromText( 'C' ) ], 1 );
		$this->assertFalse( $status->isGood() );
		if ( !$status->isOK() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'A' => [
				'2000-01-01' => 1,
			],
			'B' => [
				'2000-01-01' => null,
			],
			'C' => [
				'2000-01-01' => null,
			],
		], $status->getValue() );
		$this->assertTrue( $status->hasMessage( '500' ) );
		$this->assertSame( [ 'A' => true, 'B' => true, 'C' => false ], $status->success );
		$this->assertSame( 2, $status->successCount );
		$this->assertSame( 1, $status->failCount );

		// all error out
		$this->calls = [];
		$mockA = $this->mockNextRequest();
		$mockA->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newFatal( '500' ) );
		$mockA->method( 'getContent' )->willReturn( '' );
		$mockA->method( 'getStatus' )->willReturn( 500 );
		$mockB = $this->mockNextRequest();
		$mockB->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newFatal( '500' ) );
		$mockB->method( 'getContent' )->willReturn( '' );
		$mockB->method( 'getStatus' )->willReturn( 500 );
		$status = $service->getPageData( [ \Title::newFromText( 'A' ), \Title::newFromText( 'B' ) ], 1 );
		$this->assertFalse( $status->isOK() );
		$this->assertSame( [ 'A' => false, 'B' => false ], $status->success );
		$this->assertSame( 0, $status->successCount );
		$this->assertSame( 2, $status->failCount );
	}

	public function testGetSiteData() {
		$service = new WikimediaPageViewService(
			$this->mockHttpRequestFactory(),
			'http://endpoint.example.com/',
			[ 'project' => 'project.example.com' ],
			false
		);
		$this->mockDate( $service, '2000-01-05' );

		// valid request
		$mock = $this->mockNextRequest( function ( $url ) {
			$this->assertSame( 'http://endpoint.example.com/metrics/pageviews/aggregate/'
			. 'project.example.com/all-access/user/daily/2000010100/2000010500', $url );
		} );
		$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newGood() );
		$mock->method( 'getContent' )->willReturn( json_encode( [
			'items' => [
				[
					'project' => 'project.example.com',
					'access' => 'all-access',
					'agent' => 'user',
					'granularity' => 'daily',
					'timestamp' => '2000010100',
					'views' => 1000,
				],
				[
					'project' => 'project.example.com',
					'access' => 'all-access',
					'agent' => 'user',
					'granularity' => 'daily',
					'timestamp' => '2000010200',
					'views' => 100,
				],
				[
					'project' => 'project.example.com',
					'access' => 'all-access',
					'agent' => 'user',
					'granularity' => 'daily',
					'timestamp' => '2000010400',
					'views' => 10,
				],
			]
		] ) );
		$mock->method( 'getStatus' )->willReturn( 200 );
		$status = $service->getSiteData( 5 );
		if ( !$status->isGood() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'2000-01-01' => 1000,
			'2000-01-02' => 100,
			'2000-01-03' => null,
			'2000-01-04' => 10,
			'2000-01-05' => null,
		], $status->getValue() );

		// 404
		$this->calls = [];
		$mock = $this->mockNextRequest();
		$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newFatal( '404' ) );
		$mock->method( 'getContent' )->willReturn( $this->get404ErrorJson() );
		$mock->method( 'getStatus' )->willReturn( 404 );
		$status = $service->getSiteData( 5 );
		if ( !$status->isGood() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'2000-01-01' => null,
			'2000-01-02' => null,
			'2000-01-03' => null,
			'2000-01-04' => null,
			'2000-01-05' => null,
		], $status->getValue() );

		// genuine error
		$this->calls = [];
		$mock = $this->mockNextRequest();
		$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newFatal( '500' ) );
		$mock->method( 'getContent' )->willReturn( '' );
		$mock->method( 'getStatus' )->willReturn( 500 );
		$status = $service->getSiteData( 5 );
		$this->assertFalse( $status->isOK() );
		$this->assertTrue( $status->hasMessage( '500' ) );
	}

	public function testGetSiteData_unique() {
		$service = new WikimediaPageViewService(
			$this->mockHttpRequestFactory(),
			'http://endpoint.example.com/',
			[ 'project' => 'project.example.com' ],
			false
		);
		$this->mockDate( $service, '2000-01-05' );

		// valid request
		$mock = $this->mockNextRequest( function ( $url ) {
			$this->assertSame( 'http://endpoint.example.com/metrics/unique-devices/'
				. 'project.example.com/all-sites/daily/20000101/20000105', $url );
		} );
		$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newGood() );
		$mock->method( 'getContent' )->willReturn( json_encode( [
			'items' => [
				[
					'project' => 'project.example.com',
					'access-site' => 'all-sites',
					'granularity' => 'daily',
					'timestamp' => '20000101',
					'devices' => 1000,
				],
				[
					'project' => 'project.example.com',
					'access-site' => 'all-sites',
					'granularity' => 'daily',
					'timestamp' => '20000102',
					'devices' => 100,
				],
				[
					'project' => 'project.example.com',
					'access-site' => 'all-sites',
					'granularity' => 'daily',
					'timestamp' => '20000104',
					'devices' => 10,
				],
			]
		] ) );
		$mock->method( 'getStatus' )->willReturn( 200 );
		$status = $service->getSiteData( 5, PageViewService::METRIC_UNIQUE );
		if ( !$status->isGood() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'2000-01-01' => 1000,
			'2000-01-02' => 100,
			'2000-01-03' => null,
			'2000-01-04' => 10,
			'2000-01-05' => null,
		], $status->getValue() );

		// 404
		$this->calls = [];
		$mock = $this->mockNextRequest();
		$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newFatal( '404' ) );
		$mock->method( 'getContent' )->willReturn( $this->get404ErrorJson() );
		$mock->method( 'getStatus' )->willReturn( 404 );
		$status = $service->getSiteData( 5, PageViewService::METRIC_UNIQUE );
		if ( !$status->isGood() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'2000-01-01' => null,
			'2000-01-02' => null,
			'2000-01-03' => null,
			'2000-01-04' => null,
			'2000-01-05' => null,
		], $status->getValue() );

		// genuine error
		$this->calls = [];
		$mock = $this->mockNextRequest();
		$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newFatal( '500' ) );
		$mock->method( 'getContent' )->willReturn( '' );
		$mock->method( 'getStatus' )->willReturn( 500 );
		$status = $service->getSiteData( 5, PageViewService::METRIC_UNIQUE );
		$this->assertFalse( $status->isOK() );
		$this->assertTrue( $status->hasMessage( '500' ) );
	}

	public function testGetTopPages() {
		$service = new WikimediaPageViewService(
			$this->mockHttpRequestFactory(),
			'http://endpoint.example.com/',
			[ 'project' => 'project.example.com' ],
			false
		);
		$this->mockDate( $service, '2000-01-05' );

		// valid request
		$mock = $this->mockNextRequest( function ( $url ) {
			$this->assertSame( 'http://endpoint.example.com/metrics/pageviews/top/'
				. 'project.example.com/all-access/2000/01/05', $url );
		} );
		$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newGood() );
		$mock->method( 'getContent' )->willReturn( json_encode( [
			'items' => [
				[
					'project' => 'project.example.com',
					'access' => 'all-access',
					'year' => '2000',
					'month' => '01',
					'day' => '05',
					'articles' => [
						[
							'article' => 'Main_Page',
							'views' => 1000,
							'rank' => 1,
						],
						[
							'article' => 'Special:Search',
							'views' => 100,
							'rank' => 2,
						],
						[
							'article' => '404.php',
							'views' => 10,
							'rank' => 3,
						],
					],
				],
			 ]
		] ) );
		$mock->method( 'getStatus' )->willReturn( 200 );
		$status = $service->getTopPages();
		if ( !$status->isGood() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'Main_Page' => 1000,
			'Special:Search' => 100,
			'404.php' => 10,
		], $status->getValue() );

		// 404
		$this->calls = [];
		$mock = $this->mockNextRequest();
		$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newFatal( '404' ) );
		$mock->method( 'getContent' )->willReturn( $this->get404ErrorJson() );
		$mock->method( 'getStatus' )->willReturn( 404 );
		$status = $service->getTopPages();
		if ( !$status->isGood() ) {
			$this->fail( \Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [], $status->getValue() );

		// genuine error
		$this->calls = [];
		$mock = $this->mockNextRequest();
		$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newFatal( '500' ) );
		$mock->method( 'getContent' )->willReturn( '' );
		$mock->method( 'getStatus' )->willReturn( 500 );
		$status = $service->getTopPages();
		$this->assertFalse( $status->isOK() );
		$this->assertTrue( $status->hasMessage( '500' ) );
	}
}
