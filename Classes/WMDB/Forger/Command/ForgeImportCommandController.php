<?php
namespace WMDB\Forger\Command;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "WMDB.Forger".           *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use WMDB\Forger\Utilities\ElasticSearch\ElasticSearchConnection;
use TYPO3\Flow\Utility;
use Elastica;
use TYPO3\Flow\Cli;
use Redmine;

/**
 * @Flow\Scope("singleton")
 */
class ForgeImportCommandController extends Cli\CommandController {

	/**
	 * @var \Redmine\Client;
	 */
	protected $redmineClient;

	/**
	 * @var array The Settings array from our YAML file
	 */
	protected $settings;

	/**
	 * @var \Elastica\Index
	 */
	protected $elasticIndex;
	
	protected $lockFile;

	protected $moreIssuesFromGerrit = true;

	/**
	 * @var ElasticSearchConnection
	 */
	protected $connection;

	/**
	 * @var array
	 */
	protected $processedTickets = array();

	/**
	 * Initializes all client connections to forge and to Elasticsearch
	 *
	 * @param string $mode
	 * @throws \TYPO3\Flow\Exception
	 */
	protected function startUp($mode = '') {
		if ($mode === 'elevated') {
			$this->redmineClient = new Redmine\Client(
				$this->settings['Redmine']['url'],
				$this->settings['Redmine']['apiKeyElevated']
			);
		} else {
			$this->redmineClient = new Redmine\Client(
				$this->settings['Redmine']['url'],
				$this->settings['Redmine']['apiKey']
			);
		}
		$this->connection = new ElasticSearchConnection();
		$this->connection->init();

		$this->elasticIndex = $this->connection->getIndex();
		$this->lockFile = FLOW_PATH_DATA.'Persistent/WMDB.Forger.lock';
	}

	/**
	 * Test command - imports Forge Issue #63618
	 */
	public function testCommand() {
		$this->startUp();
		#$res = $this->redmineClient->api('issue')->show(63618, array('include' => array('journals')));
		#$this->addDocumentToElastic($res['issue']);
		$res = $this->getReviewBlock(1,1);
		\TYPO3\Flow\var_dump($res);
	}

	/**
	 * Imports the last 50 issues from forge
	 * @throws \Exception
	 */
	public function deltaCommand() {
		$this->startUp();
		$lockDate = $this->readLockFile();
		$this->getIssuesSinceLastRun($lockDate);
	}

	/*
	 * Imports the last 35 reviews from gerrit
	 */
	public function gerritDeltaCommand() {
		$this->startUp();
		$result = $this->getReviewBlock(0, 35);
		foreach ($result as $review) {
			$this->addDocumentToElastic($review, 'review');
		}
	}


	/**
	 * Write a lock file that holds the timestamp of the last run
	 * @param string $offset
	 */
	private function writeLockFile($offset = '') {
		if($offset != '') {
			$lockDate = new \DateTime();
			$lockDate->sub(new \DateInterval($offset));
		} else {
			$lockDate = new \DateTime();
		}
		file_put_contents($this->lockFile, serialize($lockDate));
	}

	/**
	 * Read the lockfile and return the date of the last run
	 * @throws \Exception
	 * @return mixed
	 */
	private function readLockFile() {
		if(file_exists($this->lockFile)) {
			$lockDate = unserialize(file_get_contents($this->lockFile));
			if(is_a($lockDate, 'DateTime')) {
				return $lockDate;
			} else {
				throw new \Exception('lockFile contains bogus');
			}
		} else {
			$this->writeLockFile('P1D');
		}
	}

	/**
	 * Get the updates since the last run
	 * GET /issues.xml?updated_on=%3E%3D2014-01-02T08:12:32Z
	 * @param \DateTime $lastRun
	 */
	private function getIssuesSinceLastRun(\DateTime $lastRun) {
		//@todo implement getIssuesSinceLastRun
		$config = [
			'limit' => 50,
			'project_id' => 27,
			'status_id' => '*',
			'sort' => 'updated_on:desc'
		];
		$result = $this->redmineClient->api('issue')->all($config);
		foreach ($result['issues'] as $doc) {
			$this->getTicketAndJournal($doc['id']);
		}
	}

	/**
	 * Imports a single ticket.
	 * A sleep(1) might be useful here to not hammer forge
	 * @param int $issueId
	 */
	private function getTicketAndJournal($issueId) {
		$res = $this->redmineClient->api('issue')->show($issueId, array('include' => array('journals')));
		if (is_array($res)) {
			$this->addDocumentToElastic($res['issue']);
		}
	}

	/**
	 * Runs a full import of all issues, both open and closed
	 * per Issue it needs to call getTicketAndJournal()
	 * @param int $start
	 */
	public function fullCommand($start = -100) {
		$this->startUp();
		$fullAmount = 25000;
		$currentSlot = $start;
		$perRun = 100;
		while ($currentSlot < $fullAmount) {
			$result = $this->redmineClient->api('issue')->all(
				[
					'limit' => $perRun,
					'offset' => ($currentSlot + $perRun),
					'project_id' => 27,
					'status_id' => '*',
					'sort' => 'updated_on:desc'
				]
			);
			foreach ($result['issues'] as $doc) {
				$this->getTicketAndJournal($doc['id']);
			}
			$currentSlot = ($currentSlot + $perRun);
		}

	}

	/**
	 * Runs a full import of all reviews
	 */
	public function fullGerritCommand() {
		$this->startUp();
		$fullAmount = 20000;
		$currentSlot = 0;
		$perRun = 100;
		while ($currentSlot <= $fullAmount) {
			if($this->moreIssuesFromGerrit) {
				$result = $this->getReviewBlock($currentSlot, $perRun);
				foreach ($result as $review) {
					$this->addDocumentToElastic($review, 'review');
				}
			}
			$currentSlot = ($currentSlot + $perRun);
		}
	}

	/**
	 * @param $current
	 * @param $perRun
	 * @return array
	 * @throws \Exception
	 */
	protected function getReviewBlock($current, $perRun) {
		$moreChanges = false;
		$out = [];
		$json = file_get_contents('https://review.typo3.org/changes/?q=project:Packages/TYPO3.CMS&n='.$perRun.'&start='.$current.'&o=LABELS&o=CURRENT_REVISION&o=ALL_FILES&o=CURRENT_COMMIT');
		#$json = file_get_contents('https://review.typo3.org/changes/?q=project:Packages/TYPO3.CMS%20change:35185%20OR%20change:23506%20OR%20change:35060%20OR%20change:35315%20OR%20change:35104&n='.$perRun.'&start='.$current.'&o=LABELS&o=CURRENT_REVISION&o=ALL_FILES');
		#$json = file_get_contents('https://review.typo3.org/changes/?q=project:Packages/TYPO3.CMS%20AND%20change:35104&n='.$perRun.'&start='.$current.'&o=LABELS&o=CURRENT_REVISION&o=ALL_FILES');
		#$json = file_get_contents('https://review.typo3.org/changes/?q=project:Packages/TYPO3.CMS%20AND%20change:35412&n='.$perRun.'&start='.$current.'&o=LABELS&o=CURRENT_REVISION&o=ALL_FILES&o=CURRENT_COMMIT');
		$data = json_decode(str_replace(")]}'", '', $json), true);
//		\TYPO3\Flow\var_dump($data);
		foreach ($data as $change) {
			$labels = $this->extractLabels($change['labels']);
			if(!isset($change['mergeable'])) {
				$change['mergeable'] = false;
			}

			$out[$change['_number']] = [
				'id' => $change['_number'],
				'change_id' => $change['change_id'],
				'status' => $change['status'],
				'subject' => $change['subject'],
				'branch' => $change['branch'],
				'topic' => (isset($change['topic'])) ? $change['topic'] : 'no topic',
				'mergeable' => ($change['mergeable'] === true) ? 'yes' : 'no',
				'affected_files' => count($change['revisions'][$change['current_revision']]['files']),
				'insertions' => $change['insertions'],
				'deletions' => $change['deletions'],
				'positive_reviews' => $labels['positive_reviews'],
				'negative_reviews' => $labels['negative_reviews'],
				'positive_verifications' => $labels['positive_verifications'],
				'negative_verifications' => $labels['negative_verifications'],
				'patchsets' => $change['revisions'][$change['current_revision']]['_number'],
				'created_on' => $this->fixDateFormat(str_replace('-', '/', $change['created'])),
				'updated_on' => $this->fixDateFormat(str_replace('-', '/', $change['updated'])),
				'releases' => $this->extractReleases($change['revisions'][$change['current_revision']]['commit']['message']),
				'owner' => (isset($change['owner']['name'])) ? $change['owner']['name'] : 'no one',
			];
			if(isset($change['_more_changes'])) {
				$moreChanges = true;
			}
		}
		if($moreChanges === false) {
			$this->moreIssuesFromGerrit = false;
		}
		return $out;
	}

	/**
	 * @param string $message
	 * @throws \Exception
	 * @return array
	 */
	protected function extractReleases($message) {
		$pattern = '/Releases:\s*(.*)/';
		preg_match($pattern, $message, $matches);
		if(!isset($matches[1])) {
			return ['omitted'];
		}
		/**
		 * fix Stuff
		 */
		if(strstr($matches[1], 'master.')) {
			$matches[1] = str_replace('master.', 'master,', $matches[1]);
		}
		if(strstr($matches[1], '4.7.')) {
			$matches[1] = str_replace('4.7.', '4.7,', $matches[1]);
		}
		if(strstr($matches[1], '4.5 LTS')) {
			$matches[1] = str_replace('4.5 LTS', '4.5', $matches[1]);
		}
		$releaseArray = Utility\Arrays::trimExplode(',', $matches[1]);
		foreach ($releaseArray as $key => $release) {
			if(strstr($release, ' ')) {
				\TYPO3\Flow\var_dump($releaseArray);
				throw new \Exception('Release contains space?');
			}
			$releaseArray[$key] = $release;
		}
		return $releaseArray;
	}

	/**
	 * @param array $labels
	 * @throws \Exception
	 * @return array
	 */
	protected function extractLabels(array $labels) {
		$out = [
			'positive_reviews' => 0,
			'negative_reviews' => 0,
			'positive_verifications' => 0,
			'negative_verifications' => 0,

		];
		foreach ($labels as $labelType => $subrow) {
			switch($labelType) {
				case 'Verified':
					$key = '_verifications';
					break;
				case 'Code-Review':
					$key = '_reviews';
					break;
				default:
					throw new \Exception('Unknown Label type '.$labelType);
			}
			foreach ($subrow as $reviewType => $amount) {
				switch($reviewType) {
					case 'approved':
						$out['positive' . $key] = $out['positive' . $key] + (count($amount) * 2);
						break;
					case 'recommended':
						$out['positive' . $key] = $out['positive' . $key] + (count($amount) * 1);
						break;
					case 'rejected':
						$out['negative' . $key] = $out['negative' . $key] + (count($amount) * 2);
						break;
					case 'disliked':
						$out['negative' . $key] = $out['negative' . $key] + (count($amount) * 1);
						break;
					/**
					 * Value is passed in a strange way, we just ignore it
					 */
					case 'value':
						break;
					case 'blocking':
						break;
					default:
						\TYPO3\Flow\var_dump($subrow);
						throw new \Exception('Unknown Review Type '.$labelType.':'.$reviewType);
				}
			}
		}
		return $out;
	}

	/**
	 * @param array $document
	 * @param string $type
	 * @throws \Exception
	 */
	protected function addDocumentToElastic(array $document, $type = 'issue') {
		switch($type) {
			case 'issue':
				if (isset($document['custom_fields'])) {
					foreach ($document['custom_fields'] as $customField) {
						switch ($customField['id']) {
							case 4:
								$document['typo3_version'] = (isset($customField['value'])?$customField['value']:'-');
								break;
							case 5:
								$document['php_version'] = (isset($customField['value'])?$customField['value']:'-');
								break;
							case 8:
								$document['complexity'] = (isset($customField['value'])?$customField['value']:'-');
								break;
							case 15:
								$document['isregression'] = (isset($customField['value'])? true : false);
								break;
							case 18:
								$document['focus']['name'] = (isset($customField['value'])?$customField['value']:'-');
								break;
							default:
						}
					}
				}
				$document['updated_on'] = $this->fixDateFormat($document['updated_on']);
				$document['created_on'] = $this->fixDateFormat($document['created_on']);
				$type = new Elastica\Type($this->elasticIndex, 'issue');
				try {
					$testIfDocExists = $type->getDocument($document['id']);
				} catch (\Exception $e) {
					$message = new \WMDB\Forger\Utilities\Slack\Message();
					$message->sendMessage($document);
				}
				break;
			case 'review':
				$type = new Elastica\Type($this->elasticIndex, 'review');
				break;
			case 'user':
				$type = new Elastica\Type($this->elasticIndex, 'user');
				break;
			default:
		}
		#\TYPO3\Flow\var_dump($type);
		$doc = new Elastica\Document($document['id'], $document);
		#\TYPO3\Flow\var_dump($doc);
		$type->addDocument($doc);
		#sleep(1);
	}

	/**
	 * Injects the settings from Settings.yaml
	 * @param array $settings
	 */
	public function injectSettings(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * @param string $string
	 * @return string
	 */
	protected function fixDateFormat($string) {
		$pattern = '/([0-9]{4}\/[0-9]{2}\/[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}|[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})/';
		preg_match($pattern, $string, $matches);
		if(isset($matches[1])) {
			return $matches[1];
		} else {
			return 'broken';
		}
	}

	/**
	 * Imports users from Redmine
	 */
	public function usersCommand() {
		$this->startUp('elevated');
		$memberships = $this->redmineClient->api('membership')->all('typo3cms-core', array('limit' => 1000));
		if (isset($memberships['memberships'])) {
			foreach ($memberships['memberships'] as $userData) {
				$this->insertUser($userData['user']['id'], $userData['user']['login']);
			}
		} else {
			$this->outputLine('Cannot fetch memberships. Maybe the user may not read them?');
		}
	}

	/**
	 * @param int $userId
	 * @param string $username
	 */
	protected function insertUser($userId = 0, $username = '') {
		$userData = $this->redmineClient->api('user')->show($userId);
		$compiledData = [
			'id' => $userId,
			'username' => $username,
			'email' => (isset($userData['user']['mail']) ? $userData['user']['mail'] : NULL),
			'fullname' => $userData['user']['firstname'].' '.$userData['user']['lastname']
		];
		$this->addDocumentToElastic($compiledData, 'user');
	}
}