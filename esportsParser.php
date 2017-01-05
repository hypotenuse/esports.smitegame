<?php

/*
 * @author: Ivan P (hypotenuse)
 * @mail: hypotenuse@yandex.ru
 *
 */

require 'vendor/autoload.php';
require 'httpful.phar';

header('Content-Type: application/json;charset=utf-8');

if (class_exists('DOMDocument')) {

	class esportsParser {
		
		public function __construct() {}

		private function getHTML($url) {
			$DOMDocument = new DOMDocument();
			
			$response = \Httpful\Request::get($url)->send();
			@$DOMDocument->loadHTML($response); //file_get_contents($url)

			return $DOMDocument;
		}

		private function toJSON(array $array, $mask = NULL) {
			return json_encode(
				$array,
				is_null($mask) ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT : $mask
			);
		}

		private function defineTeamCode($teamLink) {
			// http://esports.smitegame.com/team/{team-code}/
			$matches = [];
			$match = preg_match('/\/team\/(.+?)\//', $teamLink, $matches);
			return $matches[1];
		}

		public function getMatches() {

			$url = 'http://esports.smitegame.com/fall-split-landing-page/';

			// Find All tr-elements 
			$trs = $this->getHTML($url)->getElementsByTagName('tr');
			$k = 0;
			$matchsets = [];
			$matches = [];
			$regionRE = '/\s+region\-(1|2)\s+/';

			// If some td has post-match-link class in a list of tds then return true otherwise false
			$defineStatus = function($tdNodes) {
				$j = -1;
				while(++$j < $tdNodes->length) {
					if ('post-match-link' == $tdNodes->item($j)->getAttribute('class')) {
						return [
							1,
							$tdNodes->item($j)->getElementsByTagName('a')->item(0)->getAttribute('href')
						];
					}
				}
				return [0];
			};

			$defineLeague = function($matchsetid) use ($regionRE) {
				
				$parent = $matchsetid->parentNode;

				while($parent && !($parent->hasAttribute('class') && preg_match($regionRE, $parent->getAttribute('class'), $matches))) {
					$parent = $parent->parentNode;
				}

				if ($parent && ($matches[1] == '1' || $matches[1] == '2')) {
					return $matches[1] == '1' ? 'North America' : 'Europe';
				}
				else {
					return NULL;
				}
			};

			$convertEDTDatetime = function($datetime) {

				$apmMap = [
					'12am' => 0, '1am' => 1, '2am' => 2, '3am' => 3, '4am' => 4, '5am' => 5, '6am' => 6, '7am' => 7, 
					'8am' => 8, '9am' => 9, '10am' => 10, '11am' => 11, '12pm' => 12, '1pm' => 13, '2pm' => 14, '3pm' => 15,
					'4pm' => 16, '5pm' => 17, '6pm' => 18, '7pm' => 19, '8pm' => 20, '9pm' => 21, '10pm' => 22, '11pm' => 23
				];

				$monthMap = [
					'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 
					'oct' => 10, 'nov' => 11, 'dec' => 12 
				];
				
				// Thursday Sep 24, 2015 @ 5:30PM EDT
				preg_match('/^(\w+)\s+(\w+)\s+(\d{1,2})\,\s+(\d{1,4})\s+\@\s+(\d{1,2})\:(\d{2})(PM|AM)\s+EDT/i', trim($datetime), $dateEntities);

				$customDate = \Carbon\Carbon::create(
					$dateEntities[4],
					$monthMap[strtolower($dateEntities[2])],
					$dateEntities[3],
					$apmMap[$dateEntities[5] . strtolower($dateEntities[7])],
					$dateEntities[6],
					0
				);
				
				// EDT is 4 hours behind UTC (GMT)
				$customDate->addHours(4);

				// 2017-11-20T00:00:00+00:00
				return $customDate->toIso8601String();
			};

			// Find All tr-elements with data-matchsetid attribute
			foreach($trs as $tr) {
				if ($trs->item($k)->hasAttribute('data-matchsetid')) {

					// Contains team images url
					$images = $trs->item($k)->getElementsByTagName('img');

					// Contains team codes
					//$spans = $trs->item($k)->getElementsByTagName('span');

					$match_status = $defineStatus($trs->item($k)->getElementsByTagName('td'));

					if (count($match_status) == 2) {
						$match_result_url = $match_status[1];
					}
					else {
						$match_result_url = NULL;
					}

					$match_status = $match_status[0];

					if ($trs->item($k)->previousSibling->nodeType == XML_TEXT_NODE) {
						$datetimeNode = $trs->item($k)->previousSibling->previousSibling;
					}
					else {
						$datetimeNode = $trs->item($k)->previousSibling;
					}
					
					array_push($matches, [
						
						'team1_name' => $images->item(0)->getAttribute('title'),
						'team2_name' => $images->item(1)->getAttribute('title'),

						'team1_code' => $this->defineTeamCode($images->item(0)->parentNode->getAttribute('href')), //$spans->item(0)->nodeValue, // из ссылки
						'team2_code' => $this->defineTeamCode($images->item(1)->parentNode->getAttribute('href')), //$spans->item(1)->nodeValue, // из ссылки

						'team1_image_url' => $images->item(0)->getAttribute('src'),
						'team2_image_url' => $images->item(1)->getAttribute('src'),

						'match_datetime' => $convertEDTDatetime($datetimeNode->getElementsByTagName('p')->item(0)->nodeValue),

						'league' => $defineLeague($trs->item($k)),
						
						'completed' => $match_status,

						'match_result_url' => is_null($match_result_url) ? NULL : $match_result_url
					]);

				}

				$k++;
			
			}

			return $this->toJSON($matches);
		}
		
		public function getTeams($expandPlayers = 0) {

			$url = 'http://esports.smitegame.com/fall-split-landing-page/';

			$divs = $this->getHTML($url)->getElementsByTagName('div');
			$teamsWrapper = NULL;
			$j = -1;
			$teams = [];

			while(++$j < $divs->length) {
				if ('teams-wrapper' == $divs->item($j)->getAttribute('class')) {
					$teamsWrapper = $divs->item($j);
					break;
				}
			}

			if (!is_null($teamsWrapper)) {

				$images = $teamsWrapper->getElementsByTagName('img');
				$j = -1;
				
				while (++$j < $images->length) {
					array_push($teams, [
						'name' => $images->item($j)->getAttribute('alt'),
						'code' => $this->defineTeamCode($images->item($j)->parentNode->getAttribute('href')),
						'image_url' => $images->item($j)->getAttribute('src')
					]);

					if ($expandPlayers) {
						$divs = $this->getHTML($images->item($j)->parentNode->getAttribute('href'))->getElementsByTagName('div');
						$roster = [];
						$i = -1;
						while(++$i < $divs->length) {
							if ('player-name' == $divs->item($i)->getAttribute('class')) {
								$roster[] = $divs->item($i)->getElementsByTagName('p')->item(0)->nodeValue;
							}
						}
						$teams[count($teams) - 1]['roster'] = $roster;
					}

				}
			}

			return $this->toJSON($teams);
		}
		
		public function getPlayerInfo($playerCode = NULL) {

			$definePosition = function($positionString) {
				$splitted = preg_split('/\s+/', $positionString);
				return $splitted[0];
			};

			if (!is_null($playerCode)) {

				$divs = $this->getHTML('http://esports.smitegame.com/player/'. $playerCode .'/')->getElementsByTagName('div');
				$detailsCard = NULL;
				$j = -1;
				$playerInfo = [];

				while(++$j < $divs->length) {
					if ('details-card' == $divs->item($j)->getAttribute('class')) {
						$detailsCard = $divs->item($j);
						break;
					}
				}

				if (!is_null($detailsCard)) {
					array_push($playerInfo, [
						'player_code' => $detailsCard->getElementsByTagName('h2')->item(0)->nodeValue,
						'player_name' => $detailsCard->getElementsByTagName('h3')->item(0)->nodeValue,
						'position' => $definePosition($detailsCard->getElementsByTagName('h3')->item(1)->nodeValue),
						'team_code' => $this->defineTeamCode($detailsCard->getElementsByTagName('h3')->item(1)->getElementsByTagName('a')->item(0)->getAttribute('href'))
					]);
				}

				return $this->toJSON($playerInfo);

			}
		}

		public function getMatchResult($resultsUrl = NULL) {
			if (!is_null($resultsUrl)) {

				$divs = $this->getHTML('http://esports.smitegame.com/matchset/'. $resultsUrl .'/')->getElementsByTagName('div');
				$j = -1;
				$matchesList = [];
				$_matchesList = [];

				while(++$j < $divs->length) {
					if (preg_match('/\s+match\-detail\s+/', $divs->item($j)->getAttribute('class'))) {
						$matchesList[] = $divs->item($j);
					}
				}
				
				$j = -1;

				while(++$j < count($matchesList)) {
					
					$stats = [];
					$i = -1;
					$trs = $matchesList[$j]->getElementsByTagName('tr');

					while(++$i < $trs->length) {
						if ('player-stats' == $trs->item($i)->getAttribute('class')) {
							$tds = $trs->item($i)->getElementsByTagName('td');
							
							$kda = preg_split('/\//', $tds->item(3)->textContent);

							$stats[$tds->item(1)->getElementsByTagName('p')->item(0)->firstChild->textContent] = [
								'level' => $tds->item(2)->textContent,
								'Kills' => $kda[0],
								'Deaths' => $kda[1],
								'Assists' => $kda[2],
								'Gold' => $tds->item(4)->textContent,
								'GPM' => $tds->item(5)->textContent,
								'Damage' => $tds->item(6)->textContent,
								'Tower' => $tds->item(7)->textContent
							];
						}
					}

					$_match_id = [];
					$match_id = preg_match('/\s*\#\s*(\d+?)\s*\)/', $matchesList[$j]->getElementsByTagName('div')->item(0)->textContent, $_match_id);

					array_push($_matchesList, [
						'match_id' => $_match_id[1],
						'winner_team_code' => NULL,
						'stats' => $stats
					]);
				}

				return $this->toJSON($_matchesList);
			}
		}
	}
}
else {
	throw new Exception('Cannot use esportsParser class. Make sure you have PHP5 and DOM-API installed');
}

// Handle GET parameters in order to work with esportsParser
if (isset($_GET['method']) && (new ReflectionClass('esportsParser'))->hasMethod($_GET['method'])) {
	
	$reflectionMethod = new ReflectionMethod('esportsParser', $_GET['method']);
	$params = NULL;

	if (isset($_GET['params'])) {
		try {
			$params = preg_split('/\s*\,\s*/', substr($_GET['params'], 1, -1));
		}
		catch(Exception $ex) {}
	}

	echo $reflectionMethod->invokeArgs(new esportsParser(), is_array($params) ? $params : []);

}


