<?php
/**
 * API module for AjaxComment extension
 * @ingroup API
 */
class ApiAjaxComments extends ApiBase {

	public function execute() {

		// Get the params
		$params = $this->extractRequestParams();
		$type   = $params['type'];
		$page   = $params['page'];
		$id     = array_key_exists( 'id', $params ) ? $params['id'] : 0;
		$data   = array_key_exists( 'data', $params ) ? $params['data'] : '';

		// Process the request
		$result = array();
		switch( $type ) {

			case 'add':
				$result = AjaxComments::add( $data, $page );
			break;

			case 'reply':
				$result = AjaxComments::reply( $data, $page, $id );
			break;

			case 'edit':
				$result = AjaxComments::edit( $data, $page, $id );
			break;

			case 'del':
				$result = AjaxComments::delete( $page, $id );
			break;

			case 'like':
				$msg = AjaxComments::like( $data, $id );
				$comment = AjaxComments::getComment( $id );
				$result = array(
					'like' => $comment['like'],
					'dislike' => $comment['dislike']
				);
			break;

			case 'get':
				$result = AjaxComments::getComments( $page, $id );
			break;

			default:
				$result['error'] = "unknown action";
		}

		// Return the result data
		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	public function isWriteMode() {
		return true;
	}

	public function mustBePosted() {
		return true;
	}

	public function getAllowedParams( $flags = 0 ) {
		return array(
			'type' => array(
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			),
			'page' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => true
			),
			'id' => array(
				ApiBase::PARAM_TYPE => 'integer',
			),
			'data' => array(
				ApiBase::PARAM_TYPE => 'string',
			),
		);
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Extension:AjaxComments';
	}
}
