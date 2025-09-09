<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\Page\PageReference;
use MediaWiki\Title\TitleFormatter;

/**
 * Expose PageViewService::getPageData().
 */
class ApiQueryPageViews extends ApiQueryBase {

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		private readonly PageViewService $pageViewService,
		private readonly TitleFormatter $titleFormatter,
	) {
		parent::__construct( $query, $moduleName, 'pvip' );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$continue = $params['continue'];
		$pageSet = $this->getPageSet();
		$titles = $pageSet->getMissingPages()
			+ $pageSet->getSpecialPages()
			+ $pageSet->getGoodPages();

		// sort titles alphabetically and discard those already processed in a previous request
		$indexToTitle = array_map( function ( PageReference $t ) {
			return $this->titleFormatter->getPrefixedDBkey( $t );
		}, $titles );
		asort( $indexToTitle );
		$indexToTitle = array_filter( $indexToTitle, static function ( $title ) use ( $continue ) {
			return $title >= $continue;
		} );
		$titleToIndex = array_flip( $indexToTitle );
		$titles = array_filter( array_values( array_map( static function ( $index ) use ( $titles ) {
			return $titles[$index] ?? null;
		}, $titleToIndex ) ) );

		$metric = Hooks::getApiMetricsMap()[$params['metric']];
		$status = $this->pageViewService->getPageData( $titles, $params['days'], $metric );
		if ( $status->isOK() ) {
			$this->addMessagesFromStatus( Hooks::makeWarningsOnlyStatus( $status ) );
			$data = $status->getValue();
			foreach ( $titles as $title ) {
				$prefixedDBkey = $this->titleFormatter->getPrefixedDBkey( $title );
				$index = $titleToIndex[$prefixedDBkey];
				$fit = $this->addData( $index, $prefixedDBkey, $data );
				if ( !$fit ) {
					$this->setContinueEnumParameter( 'continue', $prefixedDBkey );
					break;
				}
			}
		} else {
			$this->dieStatus( $status );
		}
	}

	/**
	 * @param int $index Pageset ID (real or fake)
	 * @param string $prefixedDBkey
	 * @param array $data Data for all titles.
	 * @return bool Success.
	 */
	protected function addData( $index, string $prefixedDBkey, array $data ) {
		if ( !isset( $data[$prefixedDBkey] ) ) {
			// PageViewService retains the ordering of titles so the first missing title means we
			// have run out of data.
			return false;
		}
		$value = $data[$prefixedDBkey];
		ApiResult::setArrayType( $value, 'kvp', 'date' );
		ApiResult::setIndexedTagName( $value, 'count' );
		return $this->addPageSubItems( $index, $value );
	}

	/** @inheritDoc */
	public function getCacheMode( $params ) {
		return 'public';
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return Hooks::getApiMetricsHelp( PageViewService::SCOPE_ARTICLE ) + Hooks::getApiDaysHelp() + [
			'continue' => [
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=query&titles=Main_Page&prop=pageviews' => 'apihelp-query+pageviews-example',
		];
	}

	/** @inheritDoc */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:PageViewInfo';
	}
}
