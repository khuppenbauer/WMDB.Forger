<?php
namespace WMDB\Forger\Graph\Forge;

use WMDB\Forger\Graph\AbstractGraph;
use WMDB\Forger\Utilities\ElasticSearch as Es;
use Elastica as El;

/**
 * Class Velocity
 * @package WMDB\Forger\Graph\Gerrit
 */
class Burndown extends AbstractGraph {

	protected $dateInterval = 'week';

	/**
	 * @return array
	 */
	protected function getData() {
		$rawData = [];
		$lastRow = [
			'closed' => 1,
			'open' => 1
		];
		$open = $this->getOpenedReviews();
		$close = $this->getClosedReviews();
		$result = array_replace_recursive($open, $close);
		ksort($result);
		foreach ($result as $date => $rows) {
			$rawData[] = [
				'date' => $date,
				'open' => (isset($rows['open']) ? $rows['open'] : $lastRow['open']),
				'closed' => (isset($rows['closed']) ? $rows['closed'] : $lastRow['closed'])
			];
			if(!isset($rows['closed'])) {
				$rows['closed'] = $lastRow['closed'];
			}
			if(!isset($rows['open'])) {
				$rows['open'] = $lastRow['open'];
			}
			$lastRow = $rows;
		}
		foreach ($rawData as $key => $values) {
			$rawData[$key]['diff'] = ($values['open'] - $values['closed']);
			unset($rawData[$key]['open']);
			unset($rawData[$key]['closed']);
		}
		$this->chartData = [
			'chartData' => $rawData,
			'lines' => [
				'diff' => [
					'color' => '#E69D00',
					'title' => 'Days'
				]
			]
		];
	}

	/**
	 * Get's opened reviews over time
	 * @return array
	 */
	private function getOpenedReviews() {
		$opened = [];
		$fullRequest = [
			'query' => [
				'match_all' => []
			],
			'size' => 0,
			'aggregations' => [
				'over_time' => [
					'date_histogram' => [
						'field' => 'created_on',
						'interval' => $this->dateInterval
					],
				]
			]
		];
		$search = $this->connection->getIndex()->createSearch($fullRequest);
		$search->addType('issue');
		$resultSet = $search->search();
		$data = $resultSet->getAggregations();
		$openSum = 0;
		foreach ($data['over_time']['buckets'] as $frame) {
			$openSum = $openSum + $frame['doc_count'];
			$date = $this->fixTime($frame['key_as_string']);
			$opened[$date]['open'] = $openSum;
		}
		return $opened;
	}

	/**
	 * Get's closed reviews over time
	 * @return array
	 */
	private function getClosedReviews() {
		$closed = [];
		$fullRequest = [
			'query' => [
				'bool' => [
					'must' => [
						'terms' => [
							'status.name' => [
								'Closed',
								'Resolved',
								'Rejected'
							]
						],
					],

				],
			],
			'size' => 0,
			'aggregations' => [
				'over_time' => [
					'date_histogram' => [
						'field' => 'updated_on',
						'interval' => $this->dateInterval
					],
				]
			]
		];
		$search = $this->connection->getIndex()->createSearch($fullRequest);
		$search->addType('issue');
		$resultSet = $search->search();
		$data = $resultSet->getAggregations();
		$closeSum = 0;
		foreach ($data['over_time']['buckets'] as $frame) {
			$closeSum = $closeSum + $frame['doc_count'];
			$date = $this->fixTime($frame['key_as_string']);
			$closed[$date]['closed'] = $closeSum;
		}
		return $closed;
	}
}