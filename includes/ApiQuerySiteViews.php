<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Api\ApiResult;

/**
 * Expose PageViewService::getSiteData().
 */
class ApiQuerySiteViews extends ApiQueryBase {

	private PageViewService $pageViewService;

	public function __construct(
		$query,
		$moduleName,
		PageViewService $pageViewService
	) {
		parent::__construct( $query, $moduleName, 'pvis' );
		$this->pageViewService = $pageViewService;
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

	public function getCacheMode( $params ) {
		return 'public';
	}

	public function getAllowedParams() {
		return Hooks::getApiMetricsHelp( PageViewService::SCOPE_SITE ) + Hooks::getApiDaysHelp();
	}

	protected function getExamplesMessages() {
		return [
			'action=query&meta=siteviews' => 'apihelp-query+siteviews-example',
			'action=query&meta=siteviews&pvismetric=uniques' => 'apihelp-query+siteviews-example2',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:PageViewInfo';
	}
}
