<?php
namespace WMDB\Forger\Controller;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "WMDB.Forger".           *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use Redmine;
use TYPO3\Flow\Mvc\Controller\ActionController;
use TYPO3\Flow\Utility\Arrays;

/**
 * Class ForgeController
 * @package WMDB\Forger\Controller
 */
class ForgeController extends ActionController {

	const RELATION_TYPE = 'follows';

	/**
	 * A list of IANA media types which are supported by this controller
	 *
	 * @var array
	 * @see http://www.iana.org/assignments/media-types/index.html
	 */
	protected $supportedMediaTypes = array('application/json');

	/**
	 * @var array
	 */
	protected $viewFormatToObjectNameMap = array(
			'json' => 'TYPO3\Flow\Mvc\View\JsonView'
	);

	/**
	 * @var \Redmine\Client;
	 */
	protected $redmineClient;

	/**
	 * Create follow up issue to given issue
	 *
	 * @param array $issue
	 */
	public function createAction($issue) {
		$redmineClient = new Redmine\Client(
			$this->settings['Redmine']['url'],
			$this->settings['Redmine']['apiKey']
		);
		$id = $issue['related_id'];
		unset($issue['related_id']);
		$params = $issue;

		 // get additional issue data and assign to new issue
		$res = $redmineClient->api('issue')->show($id);
		$params['project_id'] = Arrays::getValueByPath($res, 'issue.project.id');
		$params['category_id'] = Arrays::getValueByPath($res, 'issue.category.id');

		$newIssue = $redmineClient->api('issue')->create($params);

		 // create relation
		$relation['issue_to_id'] = $id;
		$relation['relation_type'] = self::RELATION_TYPE;
		$json = json_encode(array('relation' => $relation));
		$redmineClient->post('/issues/' . (string)$newIssue->id . '/relations.json', $json);

		$this->view->assign('value', json_decode(json_encode((array) $newIssue), 1));
	}
}