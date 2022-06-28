<?php

namespace MediaWiki\Extension\PageViewInfo;

use ApiBase;
use ApiQueryBase;
use ApiResult;
use MediaWiki\MediaWikiServices;
use Title;

/**
 * Expose PageViewService::getPageData().
 */
class ApiQueryPageViews extends ApiQueryBase {
	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'pvip' );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$continue = $params['continue'];
		$titles = $this->getPageSet()->getMissingTitles()
			+ $this->getPageSet()->getSpecialTitles()
			+ $this->getPageSet()->getGoodTitles();

		// sort titles alphabetically and discard those already processed in a previous request
		$indexToTitle = array_map( static function ( Title $t ) {
			return $t->getPrefixedDBkey();
		}, $titles );
		asort( $indexToTitle );
		$indexToTitle = array_filter( $indexToTitle, static function ( $title ) use ( $continue ) {
			return $title >= $continue;
		} );
		$titleToIndex = array_flip( $indexToTitle );
		$titles = array_filter( array_values( array_map( static function ( $index ) use ( $titles ) {
			return $titles[$index] ?? null;
		}, $titleToIndex ) ) );

		/** @var PageViewService $service */
		$service = MediaWikiServices::getInstance()->getService( 'PageViewService' );
		$metric = Hooks::getApiMetricsMap()[$params['metric']];
		$status = $service->getPageData( $titles, $params['days'], $metric );
		if ( $status->isOK() ) {
			$this->addMessagesFromStatus( Hooks::makeWarningsOnlyStatus( $status ) );
			$data = $status->getValue();
			foreach ( $titles as $title ) {
				$index = $titleToIndex[$title->getPrefixedDBkey()];
				$fit = $this->addData( $index, $title, $data );
				if ( !$fit ) {
					$this->setContinueEnumParameter( 'continue', $title->getPrefixedDBkey() );
					break;
				}
			}
		} else {
			$this->dieStatus( $status );
		}
	}

	/**
	 * @param int $index Pageset ID (real or fake)
	 * @param Title $title
	 * @param array $data Data for all titles.
	 * @return bool Success.
	 */
	protected function addData( $index, Title $title, array $data ) {
		if ( !isset( $data[$title->getPrefixedDBkey()] ) ) {
			// PageViewService retains the ordering of titles so the first missing title means we
			// have run out of data.
			return false;
		}
		$value = $data[$title->getPrefixedDBkey()];
		ApiResult::setArrayType( $value, 'kvp', 'date' );
		ApiResult::setIndexedTagName( $value, 'count' );
		return $this->addPageSubItems( $index, $value );
	}

	public function getCacheMode( $params ) {
		return 'public';
	}

	public function getAllowedParams() {
		return Hooks::getApiMetricsHelp( PageViewService::SCOPE_ARTICLE ) + Hooks::getApiDaysHelp() + [
			'continue' => [
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=query&titles=Main_Page&prop=pageviews' => 'apihelp-query+pageviews-example',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:PageViewInfo';
	}
}
