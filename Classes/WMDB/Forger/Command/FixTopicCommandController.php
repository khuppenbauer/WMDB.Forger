<?php
namespace WMDB\Forger\Command;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "WMDB.Forger".           *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use Elastica;
use TYPO3\Flow\Cli;
use Redmine;
use TYPO3\Flow\Http\Client;

/**
 * @Flow\Scope("singleton")
 */
class FixTopicCommandController extends Cli\CommandController {

	/**
	 * @var array The Settings array from our YAML file
	 */
	protected $settings;

	/**
	 * @var array
	 * 1) Nur typo3/sysext/backend/Classes/Resources => topic:FAL
	 * 2) Nur typo3/sysext/backend/* => topic:Backend
	 * 3) EXT:* + EXT:t3skin => topic:EXT:*
	 * 4) Nur EXT:t3skin => topic:EXT:t3skin
	 */
	protected $whiteList = [
		'Formengine' => [
			'typo3/sysext/backend/Classes/Form/(.*?)',
		],
		'FAL' => [
			'typo3/sysext/core/Classes/Resource/(.*?)',
			'typo3/sysext/core/Tests/Unit/Resource/(.*?)',
		],
		'3rd Party' => [
			'typo3/contrib/(.*?)'
		],
		'cObj' => [
			'typo3/sysext/frontend/Classes/ContentObject/(.*?)'
		],
		'Datahandler' => [
			'typo3/sysext/core/Classes/DataHandling/(.*?)'
		],
		'FE Page' => [
			'typo3/sysext/frontend/Classes/Page/(.*?)'
		],
		'Typoscript' => [
			'typo3/sysext/core/Classes/TypoScript/(.*?)',
			'typo3/sysext/core/Tests/Unit/TypoScript/(.*?)',
			'(typo3/sysext/frontend/Classes/Controller/TypoScriptFrontendController.php)'
		],
		'Core Tree' => [
			'typo3/sysext/core/Classes/Tree/(.*?)'
		],
		'Element Browser' => [
			'typo3/sysext/core/Classes/ElementBrowser/(.*?)'
		],
		'Core Utility' => [
			'typo3/sysext/core/Classes/Utility/(.*?)'
		],
		'Core Cache' => [
			'typo3/sysext/core/Classes/Cache/(.*?)'
		],
		'ExtJS' => [
			'typo3/js/extjs/(.*?)'
		],
		'Package Manager' => [
			'typo3/sysext/core/Classes/Package/(.*?)'
		],
		'Backend Utility' => [
			'typo3/sysext/backend/Classes/Utility/(.*?)',
			'typo3/sysext/backend/Tests/Unit/Utility/(.*?)'
		]

	];

	protected $blackList = [
//		'skinning' => [
//			'(.*).less$',
//			'(.*).css$',
//		],
		'LLL' => [
			'(.*).xlf$',
		],
		'documentation' => [
			'(.*).rst$',
		]
	];
	
	protected $counter = [
		'hasTopic' => 0,
		'blackListed' => 0,
		'cannotSet' => 0,
		'canSet' => 0,
		'backend' => 0,
		'total' => 0
	];

	/**
	 *
	 */
	public function allCommand() {
		$fullAmount = 500;
		$currentSlot = 0;
		$perRun = 10;
		while ($currentSlot <= $fullAmount) {
			$this->getReviewBlock($currentSlot, $perRun);
			$currentSlot = ($currentSlot + $perRun);
		}
		\TYPO3\Flow\var_dump($this->counter);
	}

	/**
	 * @param $current
	 * @param $perRun
	 * @return array
	 * @throws \Exception
	 */
	protected function getReviewBlock($current, $perRun) {
		$out = [];
		$json = file_get_contents('https://review.typo3.org/changes/?q=project:Packages/TYPO3.CMS%20AND%20status:open&n='.$perRun.'&start='.$current.'&o=LABELS&o=CURRENT_REVISION&o=ALL_FILES&o=CURRENT_COMMIT');
		$data = json_decode(str_replace(")]}'", '', $json), true);
		foreach ($data as $change) {
			if(!isset($change['topic'])) {
				$affectedExtensions = $this->determineFiles($change['revisions'][$change['current_revision']]['files']);
				switch(count($affectedExtensions)) {
					case 0:
						$this->counter['blackListed']++;
						break;
					case 1:
						$affectedExtensions = array_flip($affectedExtensions);
						switch($affectedExtensions[0]) {
							case 'EXT: frontend':
							case 'EXT: core':
							case 'EXT: backend':
							case 'NOTANEXT':
								#\TYPO3\Flow\var_dump($change['revisions'][$change['current_revision']]['files']);
								#sleep(1);
								$this->counter['backend']++;
								break;

							default:
								$topic = $affectedExtensions[0];
								$this->setTopic($change['id'], $topic);
						}

						break;
					default:
						#\TYPO3\Flow\var_dump($affectedExtensions);
						$this->counter['cannotSet']++;
						break;
				}
			} else {
				$this->counter['hasTopic']++;
			}
			$this->counter['total']++;
		}
		
		return $out;
	}

	/**
	 * @param string $changeId
	 * @param string $topic
	 */
	private function setTopic($changeId, $topic) {
		$user = $this->settings['Gerrit']['username'];
		$pass = $this->settings['Gerrit']['password'];
		$browser = new Client\Browser();
		$engine = new Client\CurlEngine();
		$browser->setRequestEngine($engine);
		$browser->request(
			'https://'.$user.':'.$pass.'@review.typo3.org/a/changes/'.$changeId.'/topic',
			'PUT',
			array(),
			array(),
			array(
				'PHP_AUTH_USER' => $user,
				'PHP_AUTH_PW' => $pass,
				'HTTP_CONTENT_TYPE' => 'application/json'
			),
			json_encode(array(
				'topic' => $topic

			))
		);
		$this->counter['canSet']++;
	}

	/**
	 * @param array $files
	 * @return array
	 */
	protected function determineFiles(array $files) {
		$out = [];
		foreach ($files as $fileName => $changedLines) {
			$extName = $this->getExtensionNameFromFileName($fileName);
			if($extName !== false) {
				$out[$extName] = 0;
			}
		}
		if(isset($out['EXT: t3skin']) && count($out) === 2) {
			unset($out['EXT: t3skin']);
		}
		return $out;
	}

	/**
	 * @param $fileName
	 * @return string
	 */
	protected function getExtensionNameFromFileName($fileName) {

		foreach ($this->whiteList as $topic => $paths) {
			foreach ($paths as $path) {
				$pattern = '/' . str_replace('/', '\/', $path) . '/';
				preg_match($pattern, $fileName, $matches);
				if (isset($matches[1])) {
					return $topic;
				}
			}
		}
		foreach ($this->blackList as $topic => $paths) {
			foreach ($paths as $path) {
				$pattern = '/' . str_replace('/', '\/', $path) . '/';
				preg_match($pattern, $fileName, $matches);
				if (isset($matches[1])) {
					return false;
				}
			}
		}

		$pattern = '/typo3\/sysext\/(.*?)\//';
		preg_match($pattern, $fileName, $matches);
		if(isset($matches[1])) {
			return 'EXT: '.$matches[1];
		} else {
			return 'NOTANEXT';
		}
	}

	/**
	 * Injects the settings from Settings.yaml
	 * @param array $settings
	 */
	public function injectSettings(array $settings) {
		$this->settings = $settings;
	}
}