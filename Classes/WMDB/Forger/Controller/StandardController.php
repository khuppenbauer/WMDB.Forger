<?php
namespace WMDB\Forger\Controller;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "WMDB.Forger".           *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Mvc\Controller\ActionController;
use WMDB\Forger\Graph\Forge\Full as IssueFull;
use WMDB\Forger\Graph\Gerrit\Full as GerritFull;
use WMDB\Forger\Utilities\ElasticSearch\ElasticSearch;
use WMDB\Forger\Utilities\ElasticSearch\ElasticSearchConnection;
use WMDB\Forger\Utilities\Memes\MemeUtility;

/**
 * Class StandardController
 * @package WMDB\Forger\Controller
 */
class StandardController extends ActionController {

	/**
	 * @var \TYPO3\Flow\Utility\Environment
	 * @Flow\Inject
	 */
	protected $env;

	/**
	 * @var string
	 */
	protected $context;

	/**
	 * Initializes the controller
	 */
	protected function initializeAction() {
		$context = $this->env->getContext();
		if($context == 'Development') {
			$this->context = 'DEV';
		} else {
			$this->context = 'PRD';
		}
	}

	public function helpAction() {
		$this->view->assignMultiple([
			'context' => $this->context
		]);
	}

	public function rstAction(){

		$this->view->assignMultiple([
			'context' => $this->context,
			'memeImage' => MemeUtility::getRandomMeme()
		]);
	}

	/**
	 * @param int $issueId
	 * @return null|string
	 */
	public function getIssueJsonAction($issueId = 0){
		$issueData = $this->findIssue($issueId);

		return json_encode($issueData);
	}

	/**
	 * @return void
	 */
	public function indexAction() {
		$reviewGraph = new GerritFull();
		$issueGraph = new IssueFull();
		$this->view->assignMultiple([
			'openIssues' => $issueGraph->render(),
			'openReviews' => $reviewGraph->render(),
			'context' => $this->context
		]);
	}

	/**
	 * @param string $query
	 * @param string $searchClosed
	 * @return string
	 */
	public function searchAction($query, $searchClosed = 'false') {
//		$this->view->assign('query', htmlspecialchars($query));
		$this->view->assign('query', $query);
		$this->view->assign('searchClosed', $searchClosed);
//		if($this->checkIfQueryIsIssue($query)) {
//			try {
//				$issueData = $this->findIssue($query);
//			} catch (\Elastica\Exception\NotFoundException $e) {
//				return 'Issue not found';
//			}
//			$this->findDupes($issueData);
//		} else {
			$this->findByQuery($query, $searchClosed);
			$this->view->assignMultiple([
				'mode' => 'query'
			]);
//		}
		$this->view->assign('context', $this->context);
		if (isset($_GET['filters'])) {
			$this->view->assign('filters', $_GET['filters']);
		}
	}

	/**
	 * @param $query
	 * @return bool
	 */
	protected function checkIfQueryIsIssue($query) {
		$result = FALSE;
		$pattern = '(^[0-9]{4,6}$)';
		preg_match($pattern, $query, $matches);
		if(!empty($matches)) {
			$result = TRUE;
		}
		return $result;
	}

	/**
	 * @param $singleScore
	 * @param $maxScore
	 * @return string
	 */
	protected function calculateScoring($singleScore, $maxScore) {
		return number_format(($singleScore * 100) / $maxScore);
	}

//	/**
//	 * @param array $issueData
//	 */
//	private function findDupes(array $issueData) {
//		$search = new ElasticSearch();
//
//		$searchWords = $this->splitSearchwords('-'.$issueData['id']. ' '.$issueData['subject']. ' ' . $issueData['description']);
//
//
//		$search->setSearchTerms($searchWords);
//		$results = $search->doSearch();
//
//		$this->view->assignMultiple([
//			'result' => $results,
//			'mode' => 'dupes'
//		]);
//	}

	/**
	 * @param int $issueId
	 * @return array|string
	 */
	private function findIssue($issueId) {
		$con = new ElasticSearchConnection();
		$con->init();
		$index = $con->getIndex();
		$forger = $index->getType('issue');
		try {
			$res = $forger->getDocument($issueId);
			// Need to use an ugly hack
			// funny noone found that one earlier
			// \TYPO3\Flow\var_dump($res);
			$data = $res->getData();
			$this->view->assign('issue', [
				'hit' => [
					'_source' => $data
				]
			]);
		} catch (\Exception $e) {
			$data = array(
				'error' => 'Issue #' . $issueId . ' was not found.'
			);
		}
		return $data;
	}

	/**
	 * @param string $query
	 * @param string $searchClosed
	 */
	private function findByQuery($query, $searchClosed) {
		$query = $this->escape($query);
		$search = new ElasticSearch();
		$search->setSearchTerms($query);
		$results = $search->doSearch($searchClosed);
		$this->view->assignMultiple([
			'result' => $results,
			'mode' => 'dupes'
		]);
	}

	public function calendarAction(){
		
	}

	/**
	 * Escape a value for special query characters such as ':', '(', ')', '*', '?', etc.
	 * NOTE: inside a phrase fewer characters need escaped, use {@link Apache_Solr_Service::escapePhrase()} instead
	 *
	 * @param string $value
	 * @return string
	 */
	public function escape($value) {
		//list taken from http://lucene.apache.org/java/docs/queryparsersyntax.html#Escaping%20Special%20Characters
		$pattern = '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\*|\?|:|\\\)/';
		$replace = '\\\$1';
		return preg_replace($pattern, $replace, $value);
	}

}