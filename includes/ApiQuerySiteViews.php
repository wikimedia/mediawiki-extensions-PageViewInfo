<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Api\ApiResult;

/**
 * Expose PageViewService::getSiteData().
 */
class ApiQuerySiteViews extends ApiQueryBase {

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		private readonly PageViewService $pageViewService,
	) {
		parent::__construct( $query, $moduleName, 'pvis' );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$metric = Hooks::getApiMetricsMap()[$params['metric']];
		$status = $this->pageViewService->getSiteData( $params['days'], $metric );
		if ( $status->isOK() ) {
			$this->addMessagesFromStatus( Hooks::makeWarningsOnlyStatus( $status ) );
			$result = $this->getResult();
			$value = $status->getValue();
			ApiResult::setArrayType( $value, 'kvp', 'date' );
			ApiResult::setIndexedTagName( $value, 'count' );
			$result->addValue( 'query', $this->getModuleName(), $value );
		} else {
			$this->dieStatus( $status );
		}
	}

	/** @inheritDoc */
	public function getCacheMode( $params ) {
		return 'public';
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return Hooks::getApiMetricsHelp( PageViewService::SCOPE_SITE ) + Hooks::getApiDaysHelp();
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=query&meta=siteviews' => 'apihelp-query+siteviews-example',
			'action=query&meta=siteviews&pvismetric=uniques' => 'apihelp-query+siteviews-example2',
		];
	}

	/** @inheritDoc */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:PageViewInfo';
	}
}
