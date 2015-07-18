<?php
namespace WMDB\Forger\Command;

use TYPO3\Flow\Annotations as Flow;
use Redmine;
use TYPO3\Flow\Exception;
use TYPO3\Flow\Cli;
use WMDB\Forger\Utilities\ElasticSearch as Es;
use Elastica as El;
use TYPO3\Flow\Http\Client;


/**
 * Class GerritCommandController
 * @package WMDB\Forger\Command
 */
class GerritCommandController extends Cli\CommandController {

	/**
	 * Settings from the YAML files
	 * @var array
	 */
	protected $settings;

	/**
	 * @var Es\ElasticSearchConnection
	 */
	protected $connection;

	/**
	 * @var \Redmine\Client;
	 */
	protected $redmineClient;
	
	protected $counter = array(
		'noTicket' => 0,
		'noArray' => 0,
		'close' => 0,
	);

	/**
	 * Generates the full todolist page
	 * @param string $date
	 */
	public function fullListCommand($date = '2015-01-01') {
		$this->abandonedCommand($date);
		$this->mergedWithOpenTicketsCommand($date);
	}

	/**
	 * Generates a list of abandoned reviews with open issues in Redmine Wiki Syntax
	 * @param string $date
	 */
	public function abandonedCommand($date = '2015-01-01') {
		$this->processList('status:abandoned', array('Closed', 'Rejected', 'Resolved'), $date);
	}

	/**
	 * @param string $date
	 */
	public function mergedWithOpenTicketsCommand($date = '2015-01-01') {
		$this->processList('status:merged', array('Closed', 'Rejected', 'Resolved'), $date);
	}

	/**
	 *
	 */
	public function wikiCommand() {
		$this->updateWikiPage('h2. Test from forger

		* husel
		* pusel');
	}

	/**
	 * Injects the settings from the yaml file into
	 * @param array $settings
	 */
	public function injectSettings(array $settings) {
		$this->settings = $settings;
		$this->connection = new Es\ElasticSearchConnection();
		$this->connection->init();
		$this->redmineClient = new Redmine\Client(
			$this->settings['Redmine']['url'],
			$this->settings['Redmine']['apiKey']
		);
	}


	/**
	 * @param string $gerritQuery
	 * @param array $expectedStatus
	 * @param string $date
	 * @throws Exception
	 * @internal param $data
	 */
	protected function processList($gerritQuery = 'status:abandoned', $expectedStatus = array(), $date = '2015-01-01') {
		$url = 'https://review.typo3.org/changes/?q=project:Packages/TYPO3.CMS+AND+'.$gerritQuery.'+AND+since:'.$date.'&o=CURRENT_REVISION&o=ALL_FILES&o=CURRENT_COMMIT&n=1000000';
		$json = file_get_contents($url);
		$data = json_decode(str_replace(")]}'", '', $json), true);
		foreach ($data as $change) {
			$ticketId = $this->getTicketId($change['revisions'][$change['current_revision']]['commit']['message']);
			if($ticketId > 0) {
				$this->getTicketStatus($ticketId, $change['_number'],$expectedStatus);
			}
		}
	}

	/**
	 * Tries to extract the ticket ID from the commit message
	 * @param string $message
	 * @return int
	 * @throws Exception
	 */
	protected function getTicketId($message) {
		if (
			strstr($message, '[WIP]')
			|| strstr($message, '[WIP/FEATURE]')
			|| strstr($message, '[TASK] Travis:')
			|| strstr($message, '[FEATURE] TYPO3 Webservice API')
			|| strstr($message, '[FEATURE] File references direct edit link')
			|| strstr($message, '[BUGFIX] addFieldsToPalette() removes --linebreak--')
			|| strstr($message, '[BUGFIX] Fix column layout for New Content Element')
			|| strstr($message, '[RELEASE]')
			|| strstr($message, 'I57666f350395b027b2cded2e1d7b0634ca39db62')
			|| strstr($message, 'I38b336b90f784bc4d1103c291d4947f3e99fbc7d')
			|| strstr($message, 'I70627af4b72e9c794e61452e66ff8c2cbf347c42')
			|| strstr($message, 'I91f2047ef1fefc9d870cdded9c12c319fe428140')
			|| strstr($message, 'I22c963c59adcccbe56c5995774d6296e6658bc44')
			|| strstr($message, 'I14292d28bb57eabd71ff3f88aa7fc9a3ebe248e1')
			|| strstr($message, 'I02e7e83f54f7d875e5b41ced103e9779f6507923')
			|| strstr($message, 'I1e5148fae326395bd7f1624fc4e2dcb70c307513')
			|| strstr($message, 'I9a15d4354304bbc6d06c492d6a3c9db84d8405ac')
			|| strstr($message, 'I0e839835ca2de53e75f8c4c1d9003ac3d5e3736d')
			|| strstr($message, 'I75f2f58880b9cdabe1fcb2003fe2c52c10410308')
			|| strstr($message, 'Ic3fa3632df6d30f0ebd2cfac129d22c3de74990c')
			|| strstr($message, 'I83a35a42f32a1cdc16676bb626455292a5bf7936')
			|| strstr($message, 'I31644d716c929e1ae3fd186360ef9bc7bdf26723')
			|| strstr($message, 'Ic1f376a2f804625711ade5b984e037c909837321')
			|| strstr($message, 'Ieee76e86ff35d7b8ffd64edbb004075d621e3b27')
			|| strstr($message, 'I8d16b1860679b96ce20a7f484bcccb5a1d395091')
			|| strstr($message, 'This reverts commit ')
			|| strstr($message, 'Set TYPO3 version to ')
			|| strstr($message, 'I394540677c1a4dc5dee036614969b4fdfa80532a')
			|| strstr($message, 'I245513542f205df8474dfc25ab7c1253143bbf91')
			|| strstr($message, 'I240a51ac098df1e2e1bcad0b6fb7dd949b8e6685')
			|| strstr($message, 'I3c4d311cf3f8c7001f08a4f8c9ff4138c622203a')
			|| strstr($message, 'I15c286f336fd317858d7435fcd6008a75e9e3095')
			|| strstr($message, 'I638f6d4838aa66e1d3e18ba5e4a70a6916ec5cb1')
			|| strstr($message, 'I04f37854014ca1fc454194be690816185e16d834')
		) {
			return 0;
		}
		$pattern = '/(Resolves|Fixes): #(?<issue>[0-9]{4,5})/';
		preg_match($pattern, $message, $matches);
		if(isset($matches['issue'])) {
			return $matches['issue'];
		} else {
			throw new Exception('Found no resolves tag in '.$message);
		}
	}

	/**
	 * @param int $issueId
	 * @param $gerritNumber
	 * @param array $expectedStatus
	 */
	protected function getTicketStatus($issueId, $gerritNumber, $expectedStatus = array()) {
//		$this->redmineClient = new Redmine\Client(
//			$this->settings['Redmine']['url'],
//			$this->settings['Redmine']['apiKey']
//		);
//		$res = $this->redmineClient->api('issue')->show($issueId);
//		if (is_array($res)) {
//			if(in_array($res['issue']['status']['name'], $unexpectedStatus)) {
//			}
//		} else {
//			$this->counter['noArray']++;
//		}
		

		try {
			$search = $this->connection->getIndex()->getType('issue');
			$doc = $search->getDocument($issueId)->getData();
//			\TYPO3\Flow\var_dump($doc);
			if(!in_array($doc['status']['name'], $expectedStatus)) {
				/**
				 * Check if another patchset exists
				 */
				$hasPatchset = false;
				$numberOfPatchsets = 0;
				foreach ($doc['journals'] as $journalEntry) {
					if (
						isset($journalEntry['notes'])
						&& strstr($journalEntry['notes'], 'It is available at http://review.typo3.org/')
						&& !strstr($journalEntry['notes'], 'It is available at http://review.typo3.org/'.$gerritNumber)
					) {
						$hasPatchset = true;
					}
					if (isset($journalEntry['notes']) && !strstr($journalEntry['notes'], 'It is available at http://review.typo3.org/'.$gerritNumber)) {
						$numberOfPatchsets++;
					}
				}

				if (!$hasPatchset && $numberOfPatchsets > 1) {
				}
			}
		} catch (El\Exception\NotFoundException $e) {
		}
	}

	/**
	 * Update the page https://forge.typo3.org/projects/typo3cms-core/wiki/Forgertest
	 * @deprecated Not usable because our redmine is too old
	 * @param string $content
	 * @param string $project
	 * @param string $page
	 */
	protected function updateWikiPage($content, $project = 'typo3cms-core', $page = 'Mergedorphans') {
		//content[text]
		//content[comments]
		$user = $this->settings['Gerrit']['username'];
		$pass = $this->settings['Gerrit']['password'];
		$browser = new Client\Browser();
		$engine = new Client\CurlEngine();
		$browser->setRequestEngine($engine);
		$res = $browser->request(
			'https://'.$user.':'.$pass.'@forge.typo3.org/projects/'.$project.'/wiki/'.$page.'/',
			'PUT',
			array(),
			array(),
			array(
				'PHP_AUTH_USER' => $user,
				'PHP_AUTH_PW' => $pass,
//				'HTTP_CONTENT_TYPE' => 'text/plain'
			),
			'content[text]='.rawurlencode($content).'&commit=Save'

		);
		\TYPO3\Flow\var_dump($res);
	}
}