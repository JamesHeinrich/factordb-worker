<?php
// factordb.com work fetch/submit script
// James Heinrich <james@mersenne.ca>
// https://www.mersenneforum.org/node/22384
// last-modified: 2026-10-08

define('FACTORDB_API_URL_V3', 'https://factordb.com:4059/rpc');

$configFileName = 'factordb.json';
$CONFIG = array();
$configJSONtext = '';
if (is_readable($configFileName)) {
	if ($configJSONtext = trim(file_get_contents($configFileName))) {
		if ((substr($configJSONtext, 0, 1) == '{') && (substr($configJSONtext, -1, 1) == '}')) {
			$CONFIG = json_decode($configJSONtext, true);
			if (json_last_error() != JSON_ERROR_NONE) {
				echo 'invalid JSON in '.realpath($configFileName)."\n:".json_last_error_msg()."\n";
				exit(1);
			}
		}
	}
}
define('IS_WINDOWS', (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN'));
$configDefaults = array(
	'min_digits'          =>  90,   // minimum number of digits for composites we want to factor
	'max_digits'          => 100,   // maximum number of digits for composites we want to factor, if no work is available smaller than this then sit idle for <sleepseconds>
	'batch_time'          => 600,   // target number of seconds for a batch of assignments, rate will be auto-adjusted to attempt to meet this
	'sleepseconds'        => 300,   // number of seconds to sleep between retries if factordb.com does not respond as expected for get work or submit results
	'sleepseconds_pause'  =>  30,   // number of seconds to sleep between checking if a pause_while_running program was found to be running
	'txtfile'             => __DIR__.DIRECTORY_SEPARATOR.'yafu-submissions_YYYYMMDD.txt',        // copy-append simplest factorization lines to this file after submitting each batch of results, YYYYMMDDHHMMSS will be replaced with today's datetimestamp or YYYYMMDD will be replaced with today's datestamp
	'yafu_executable'     => __DIR__.DIRECTORY_SEPARATOR.'yafu'.(IS_WINDOWS ? '-x64.exe' : ''),
	'cookie_jar'          => __DIR__.DIRECTORY_SEPARATOR.'cookies.txt',
	'in_filename'         => __DIR__.DIRECTORY_SEPARATOR.'random_composites.txt',
	'log_filename'        => __DIR__.DIRECTORY_SEPARATOR.'factor.log',
	'json_filename'       => __DIR__.DIRECTORY_SEPARATOR.'factor.json',
	'base10_filename'     => __DIR__.DIRECTORY_SEPARATOR.'factor.txt',
	'rate_filename'       => __DIR__.DIRECTORY_SEPARATOR.'factordb.rate',
	'sleep_during'        => '',    // optional, script will pause during these times, format "00:00-08:00;16:00-23:59" (etc)
	'pause_while_running' => '',    // optional, script will pause if these program running, format "photoshop.exe;prime95.exe" (etc) substring match, case-insensitive; currently only implemented for Windows
	'fdb_user_token'      => '',    // factordb.com user token (32-char hex string found on https://factordb.com/login.php when logged in)
);
foreach ($configDefaults as $key => $value) {
	if (!isset($CONFIG[$key])) {
		$CONFIG[$key] = $value;
	}
}
if (!preg_match('#^[0-9a-f]{32}$#i', $CONFIG['fdb_user_token'])) {
	echo 'Missing or invalid $CONFIG[fdb_user_token]'."\n";
	exit(1);
}
$configJSONtextNew = trim(json_encode($CONFIG, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($configJSONtextNew != $configJSONtext) {
	file_put_contents($configFileName, $configJSONtextNew);
}

$OPENSSL_ENABLED = (function_exists('openssl_get_cert_locations') && ($openssl_get_cert_locations = openssl_get_cert_locations()) && file_exists($openssl_get_cert_locations['default_cert_file'] ?? ''));

$CONFIG['sleep_periods'] = array();
if (!empty($CONFIG['sleep_during'])) {
	foreach (explode(';', $CONFIG['sleep_during']) as $sleep_range) {
		if (preg_match('#^([0-9]{2}:[0-9]{2})\\-([0-9]{2}:[0-9]{2})$#', $sleep_range, $matches)) {
			$CONFIG['sleep_periods'][] = $matches;
		} else {
			echo 'Invalid "sleep_during" value: "'.$sleep_range.'"'."\n\n";
			exit(1);
		}
	}
}
$CONFIG['_last_fetch_time'] = time(); // not really, just a safe initialization value

if (!empty($_SERVER['argv'][1])) {
	if ($_SERVER['argv'][1] == 'submit') {
		FactorDB_submit();
	} elseif ($_SERVER['argv'][1] == 'fetch') {
		FactorDB_fetch();
	} elseif ($_SERVER['argv'][1] == 'cleanup') {
		FilesCleanup();
	} else {
		echo 'Invalid argument'."\n";
	}
	exit;
}


function Rate1() {
	global $CONFIG;
	$assignmentCount = (($raw = trim(@file_get_contents($CONFIG['in_filename']))) ? count(explode("\n", $raw)) : 0);
	return file_put_contents($CONFIG['rate_filename'], date('Y-m-d H:i:s')."\t".$assignmentCount);
}
function Rate2() {
	global $CONFIG;
	$assignmentCount = (($raw = trim(@file_get_contents($CONFIG['in_filename']))) ? count(explode("\n", $raw)) : 0);
	return file_put_contents($CONFIG['rate_filename'], "\n".date('Y-m-d H:i:s')."\t".$assignmentCount, FILE_APPEND);
}
function AvgRate($avg, $count) {
	global $CONFIG;
	return file_put_contents($CONFIG['rate_filename'], date('c', time() - ceil($avg * $count))."\t".$count."\n".date('Y-m-d H:i:s')."\t".'0');
}
function IsSleepTime() {
	global $CONFIG;
	$now = date('H:i');
	foreach ($CONFIG['sleep_periods'] as $period) {
		if (($now >= $period[1]) && ($now <= $period[2])) {
			$seconds = strtotime($period[2].':00') - time();  // number of seconds until end of current period
			if ($seconds > 0) { // don't want to return a negative number if NOW > ENDTIME
				return $seconds;
			}
		}
	}
	return false;
}
function PauseWhileRunning() {
	global $CONFIG;
	static $lastCheckedTime = 0;

	if (!IS_WINDOWS) {
		// below code only works for Windows
		return false;
	}
	if (!empty($CONFIG['pause_while_running'])) {
		if ($lastCheckedTime) {
			if ($lastCheckedTime > (microtime(true) - $CONFIG['sleepseconds_pause'])) {
echo 'PauseWhileRunning() checked recently ('.number_format(microtime(true) - $lastCheckedTime, 3).'s ago), skipping current check'."\n\n\n";
				return false;
			}
		}
//echo 'PauseWhileRunning() not checked recently ('.($lastCheckedTime ? number_format(microtime(true) - $lastCheckedTime, 3).'s ago' : 'never').'), performing current check'."\n\n\n";
		$lastCheckedTime = microtime(true);
		$paused_because = '';
		$submitted_results = false;
		do {
			if (CheckForExit(false)) {
				break;
			}
			//$command = 'tasklist /FO CSV'; // tasklist just shows basename of executable, not path
			$command = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process | Select-Object ExecutablePath | ConvertTo-Csv -NoTypeInformation"';
			if ($tasklist = shell_exec($command)) {
//$runningPrograms = array_unique(explode("\n", str_replace("\r", '', str_replace('"', '', $tasklist))));
				$found_programs = array();
				foreach (explode(';', strtolower($CONFIG['pause_while_running'])) as $process) {
//echo 'Looking for "'.$process.'" in $tasklist'."\n";
					if (stripos($tasklist, $process) !== false) {
						$found_programs[$process] = $process;
						if (!$submitted_results) {
							FactorDB_submit();
							$submitted_results = true;
						}
					}
				}
				if (!empty($found_programs)) {
					//if (empty($found_programs[$paused_because])) {
						foreach ($found_programs as $process) {
							echo date('Y-m-d H:i:s').' Paused because "'.$process.'" is running (checking every '.$CONFIG['sleepseconds_pause'].' seconds)'."\n";
							$paused_because = $process;
							break;
						}
					//}
					sleep($CONFIG['sleepseconds_pause']);
				} else {
					$paused_because = '';
				}
			} else {
				echo 'FAIL: '.$command."\n\n";
				exit(1);
			}
		} while ($paused_because);
	}
	return true;
}

function FactorDB_fetch() {
	global $CONFIG, $OPENSSL_ENABLED;

	$number_to_grab = 50; // assume fetch 50 assignments if we have no rate data
	if (is_readable($CONFIG['rate_filename']) && ($rateRaw = trim(@file_get_contents($CONFIG['rate_filename'])))) {
		if (preg_match('#^(.+)\\t([0-9]+)[\\r\\n]+(.+)\\t([0-9]+)$#', $rateRaw, $matches)) {
			list($dummy, $date1, $count1, $date2, $count2) = $matches;
			$seconds = strtotime($date2) - strtotime($date1);
			if ($seconds > 10) { // avoid wild changes of number_to_grab if last batch was abnormally fast
				$seconds_per = $seconds / max($count1 - $count2, 1);
				$number_to_grab = min(500, max(ceil($CONFIG['batch_time'] / max($seconds_per, 0.1)), 1));
				echo date('Y-m-d H:i:s').' Completed '.($count1 - $count2).' assignments in '.$seconds.' seconds, '.number_format($seconds_per, 3).'s avg, grabbing '.$number_to_grab.' new assignments for '.ceil($CONFIG['batch_time']).'s batch'."\n";
			}
		}
	}
	$Composites = [];

	$ch = curl_init(FACTORDB_API_URL_V3);
	curl_setopt_array($ch, [
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST           => true,
		CURLOPT_HTTPHEADER     => [
		    'Content-Type: application/json',
		    'X-Fdb-User-Token: '.$CONFIG['fdb_user_token'],
		],
	]);
	if (!$OPENSSL_ENABLED) {
		// not recommended but may be required on some PHP installations if you don't have CA certificates properly configured
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	}
	for ($digits = $CONFIG['min_digits']; $digits <= $CONFIG['max_digits']; $digits++) {
		$thisFetchSize = $number_to_grab - count($Composites);
		if (count($Composites) >= $number_to_grab) {
//echo '$Composites now has '.count($Composites).', enough'."\n";
			break;
		} elseif ($thisFetchSize <= 0) {
			echo '$thisFetchSize='.intval($thisFetchSize).', this is not right'."\n";
			echo '$number_to_grab='.$number_to_grab."\n";
			echo 'count($Composites)='.count($Composites)."\n";
			print_r($Composites);
			exit(1);
		}
		$RPCdata = [
		    'jsonrpc' => '2.0',
		    'id'      => 1,
		    'method'  => 'download',
		    'params'  => [
		        'table'  => 'C',
		        'digits' => (int) $digits,
		        'count'  => (int) $thisFetchSize,
		        'random' => true,
		        'terms'  => false, // if true returns "(139^71-139^35-1)/1139", if false just returns decimal digit strings
		    ]
		];
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($RPCdata));
echo 'Fetching C'.$digits.' composites, qty: '.$thisFetchSize."\n";
		do {
			$output = curl_exec($ch);
			$info = curl_getinfo($ch);
			if ($info['http_code'] == 200) {
				$fdbJSON = json_decode($output, true, 512, JSON_BIGINT_AS_STRING);
				if (json_last_error() == JSON_ERROR_NONE) {
					if (!empty($fdbJSON['error'])) {
						echo 'FactorDB work fetch error:'.print_r($fdbJSON, true);
						exit(1);
					}
					foreach ($fdbJSON['result']['numbers'] as $composite) {
						$Composites['x'.$composite] = $composite; // 'x' in key to force PHP to treat numberic-string key as string not number, and to enforce unique assignments from multiple fetches
					}
					break;
				}
				echo 'FactorDB work fetch returned invalid JSON:'."\n".print_r($info, true)."\n".$output."\n\n";
				exit(1);
			}
			if ($curl_error = curl_error($ch)) {
				echo 'CURL error: '."\n".$curl_error."\n\n";
				if (preg_match('#^(Connection|Operation) timed out after [0-9]+ milliseconds$#i', $curl_error)) {
					// is ok
				} else {
					exit(1);
				}
			}
			echo date('Y-m-d H:i:s').' Fetch Work failed: curl_getinfo[http_code]='.$info['http_code'].' (expected: 200). Sleeping for '.$CONFIG['sleepseconds'].' seconds'."\n";
echo "\n".'DEBUG '.__FUNCTION__.':'.__LINE__."\n";
echo "~~~~~~~~~~~~~~~\n".$output."\n".print_r($info, true)."\n".curl_error($ch)."\n~~~~~~~~~~~~~~~~~~~~~~~~~\n";
			sleep($CONFIG['sleepseconds']);
		} while ($info['http_code'] != 200);
	}
	usort($Composites, 'gmp_cmp'); // sort into size order
//echo '$Composites:'.print_r($Composites, true);

	echo date('Y-m-d H:i:s').' '.basename($CONFIG['in_filename']).' now has '.number_format(count($Composites)).' assignments'."\n";
	if (count($Composites)) {
		file_put_contents($CONFIG['in_filename'], trim(implode("\n", $Composites))."\n"); // bug: YAFU v2.10 ignores last line in input file if it doesn't have a linebreak after
	} else {
		file_put_contents($CONFIG['in_filename'], ''); // set filesize to zero to prevent confusion/conflict
	}
	$CONFIG['_last_fetch_time'] = time(); // not really a config setting, but a convenient already-global variable
	return true;
}

function FactorDB_submit() {
	global $CONFIG, $OPENSSL_ENABLED;

	$allRPCdata = [];  // array of JSON results for batch submission
	$runtimes   = [];  // actual runtimes from JSON results
	$result_lines_text = '';
	if (file_exists($CONFIG['json_filename']) && filesize($CONFIG['json_filename']))  {
		foreach (explode("\n", file_get_contents($CONFIG['json_filename'])) as $linecounter => $line) {
			if ($line = trim($line)) {
				$decoded = json_decode($line, true, 512, JSON_BIGINT_AS_STRING);
				if (json_last_error() == JSON_ERROR_NONE) {
					if (!empty($decoded['runtime']['total'])) {
						$runtimes[] = $decoded['runtime']['total'];
					}
					$factorlist = array_merge(($decoded['factors-prime'] ?? []), ($decoded['factors-composite'] ?? []));
					$result_lines_text .= $decoded['input-decimal'].'='.implode('*', $factorlist)."\n";
					$allRPCdata[] = [
					    'jsonrpc' => '2.0',
					    'id'      => 1,
					    'method' => 'report_factors',
					    'params' => [
					        'target'  => ['expr' => (string) $decoded['input-decimal']],
					        'factors' => $factorlist,
					        'credit'  => true,
					    ]
					];
				} else {
					echo 'JSON decode error:'."\n".$line."\n\n";
					exit(1);
				}
			}
		}
		if (!empty($runtimes)) {
			AvgRate(array_sum($runtimes) / count($runtimes), count($runtimes));
		}
		if ($CONFIG['txtfile'] && !empty($result_lines_text)) {
			file_put_contents(str_replace('YYYYMMDD', date('Ymd'), str_replace('YYYYMMDDHHMMSS', date('YmdHis'), $CONFIG['txtfile'])), $result_lines_text, FILE_APPEND);
		}
	}
	if (!empty($allRPCdata)) {
echo 'Submitting:'."\n";
echo $result_lines_text."\n\n";
//print_r($allRPCdata);
//exit;

		$ch = curl_init(FACTORDB_API_URL_V3);
		curl_setopt_array($ch, [
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 30,
		    CURLOPT_RETURNTRANSFER => true,
		    CURLOPT_POST           => true,
		    CURLOPT_POSTFIELDS     => json_encode($allRPCdata),
		    CURLOPT_HTTPHEADER     => [
		        'Content-Type: application/json',
		        'X-Fdb-User-Token: '.$CONFIG['fdb_user_token'],
		    ],
		]);
		if (!$OPENSSL_ENABLED) {
			// not recommended but may be required on some PHP installations if you don't have CA certificates properly configured
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		}
		do {
			$output = curl_exec($ch);
			$info = curl_getinfo($ch);
if (stripos($output, '"error"') !== false) {
print_r($info);
print_r($output);
echo 'EXIT LINE '.__LINE__."\n";
exit(1);
}
			if ($info['http_code'] == 200) {
				$fdbJSON = json_decode($output, true, 512, JSON_BIGINT_AS_STRING);
				if (json_last_error() == JSON_ERROR_NONE) {
					if (count($fdbJSON) == count($allRPCdata)) {
						// looks like success, break out of submit loop
						break;
					} else {
						echo 'Submitted '.count($allRPCdata).' results but found '.count($fdbJSON).' responses'."\n";
						print_r($info);
						print_r($output);
						exit(1);
					}
				} else {
					echo 'FactorDB report non-JSON response'."\n";
					print_r($info);
					print_r($output);
					exit(1);
				}
			}
			if ($curl_error = curl_error($ch)) {
				echo 'CURL error: '."\n".$curl_error."\n\n";
				if (preg_match('#^(Connection|Operation) timed out after [0-9]+ milliseconds$#i', $curl_error)) {
					// is ok
				} else {
					exit(1);
				}
			}
			// factorDB is not-working in a known way (502=Bad Gateway)
			// sleep for a bit and try again
			echo date('Y-m-d H:i:s').' Fetch Submit failed: curl_getinfo[http_code]='.$info['http_code'].' (expected: 200). Sleeping for '.$CONFIG['sleepseconds'].' seconds'."\n";
echo "\n".'DEBUG '.__FUNCTION__.':'.__LINE__."\n";
echo "~~~~~~~~~~~~~~~\n".$output."\n".print_r($info, true)."\n~~~~~~~~~~~~~~~~~~~~~~~~~\n";
			sleep($CONFIG['sleepseconds']);
		} while ($info['http_code'] == 200);
	}

	FilesCleanup();
	return count($allRPCdata);
}

function FactorDB_runbatch() {
	global $CONFIG;
	static $consecutive_sleeps = 0;
	if ($raw = trim(@file_get_contents($CONFIG['in_filename']))) {
		$lines = explode("\n", $raw);
		$runtimes = array();
		while (count($lines)) {
			if (CheckForExit(false)) {
				break;
			}
			if (IsSleepTime()) {
				echo 'Sleepy time, breaking'."\n";
				break;
			}
			if (!empty($CONFIG['_last_fetch_time']) && !empty($CONFIG['batch_time'])) {
				$currentRuntime = time() - $CONFIG['_last_fetch_time'];
				if ($currentRuntime > $CONFIG['batch_time']) {
					echo 'runtime of '.$currentRuntime.'s greater than target batch time ('.$CONFIG['batch_time'].'s), breaking'."\n";
					break;
				}
			}
			PauseWhileRunning();

			$line = array_shift($lines);
			if ($line = trim($line)) {
				if (preg_match('#^[0-9]+$#', $line)) {
					$bignumber = (string) $line;
					if (strlen($bignumber) > $CONFIG['max_digits']) {
						$consecutive_sleeps++;
						FactorDB_submit();
						$real_sleep_seconds = $CONFIG['sleepseconds'] * min(10, $consecutive_sleeps);
						echo "\n".date('Y-m-d H:i:s').' Next composite in queue is '.strlen($bignumber).' digits, larger than $CONFIG[max_digits]='.$CONFIG['max_digits'].', sleeping for '.$real_sleep_seconds.' seconds until more suitable work is available'."\n\n";
						sleep($real_sleep_seconds);
						break;
					}
					$consecutive_sleeps = 0;
					$command = 'cd '.escapeshellarg(dirname($CONFIG['yafu_executable'])).' && '.(IS_WINDOWS ? '' : 'nice -n 19 ').escapeshellarg($CONFIG['yafu_executable']).' '.escapeshellarg($bignumber);

					$YAFUstarttime = microtime(true);
					if (true) {
						$output = '';
						if ($pipe = popen($command, 'rb')) {
							while ($buffer = fread($pipe, 1024)) { // buffer smaller than 1024 might not get all the data we need at once
								echo $buffer;
								$output .= $buffer;
							}
							pclose($pipe);
						} else {
							echo 'FAIL line '.__LINE__."\n";
							exit(1);
						}
					} else {
						echo $command."\n";
						echo $output = shell_exec($command);
					}
					$YAFUendtime = microtime(true);
					if (preg_match('#Total factoring time = ([0-9\\.]+) second#i', $output, $matches)) {
						$YAFUtime = floatval($matches[1]);
						$RUNtime  = floatval($YAFUendtime - $YAFUstarttime);
						$OVERhead = $RUNtime - $YAFUtime;
						$runtimes[] = $YAFUtime;
						$runtime_avg = array_sum($runtimes) / count($runtimes);
						echo 'Finished in '.number_format($RUNtime, 3).' seconds ('.number_format($YAFUtime, 3).' YAFU + '.number_format($OVERhead, 3).' overhead)'."\n";
						$ETA_seconds = count($lines) * $runtime_avg;
						$currentRuntime = time() - $CONFIG['_last_fetch_time'];
						echo number_format(count($lines)).' composites remaining in queue.  '.number_format($runtime_avg, 1).'s avg.  ETA: '.sprintf('%dm%02ds', (int) floor($ETA_seconds / 60), (int) $ETA_seconds % 60).'  (batch: '.$currentRuntime.'s of '.$CONFIG['batch_time'].'s allowed)'."\n";
					}
					$output = str_replace("\r", "\n", str_replace("\r\n", "\n", $output)); // convert Mac lineends (if present) to Unix lineends

					/*
					***factors found***
					P48 = 105312470794095830380183636997993149449446374417
					P38 = 62907891846619937346965274654661408153

					***factorization:***
					6624985522815301366662603038321544864236714061933786433484247030522935420093694421801=105312470794095830380183636997993149449446374417*62907891846619937346965274654661408153

					ans = 1
					*/
//echo '~~~~~~~~~~~~~~~~~~~~~~~~~'."\n";
//echo $output."\n";
//echo '~~~~~~~~~~~~~~~~~~~~~~~~~'."\n";
//echo 'Checking for: #'.preg_quote('***factorization:***').'[\r\n]+('.$bignumber.')=([0-9\\*]+)[\r\n]+ans = 1$#sm'."\n";
					if (preg_match('#'.preg_quote('***factorization:***').'[\r\n]+('.$bignumber.'=([0-9\\*]+))[\r\n]+ans = 1($|[\r\n])#sm', $output, $matches)) {
						// one-line factorization output (optional) added in YAFU 3.0
						// could just use it verbatim but may as well take the short time to verify that the listed factors add up
						list($dummy, $one_line_factorization, $factorlist) = $matches;
						$composite = 1;
						$factors = explode('*', $matches[2]);
						foreach ($factors as $factor) {
							$composite = gmp_mul($composite, $factor);
						}
						if (gmp_strval($composite) == $bignumber) {
							file_put_contents($CONFIG['base10_filename'], $one_line_factorization."\n", FILE_APPEND);
						} else {
							echo "\n\n\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n\n\n".$output."\n\n";
							echo 'composite('.$composite.') != bignumber('.$bignumber.') on line '.__LINE__."\n";
							print_r($matches);
							exit(1);
						}
					} elseif (preg_match('#'.preg_quote('***factors found***').'(.+)ans = 1$#sm', $output, $factorsfound)) {
echo basename(__FILE__).':'.__LINE__.': one-line-fail'."\n";
file_put_contents('one-line-fail.txt', $output);
exit(1);
						preg_match_all('#^([PC])([0-9]+) = ([0-9]+)$#m', $factorsfound[1], $matchset, PREG_SET_ORDER);
						if (!empty($matchset)) {
							$composite = 1;
							$factors = array();
							foreach ($matchset as $entry) {
								list($dummy, $PC, $digits, $factor) = $entry;
								if ($digits == strlen($factor)) {
									$composite = gmp_mul($composite, $factor);
									$factors[(string) $factor] = log($factor, 2);
								} else {
									echo "\n\n\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n\n\n".$output."\n\n";
									echo 'Digit count mismatch on line '.__LINE__."\n";
									print_r($entry);
									exit(1);
								}
							}
							if ($composite == $bignumber) {
								arsort($factors);
								file_put_contents($CONFIG['base10_filename'], $bignumber.'='.implode('*', array_keys($factors))."\n", FILE_APPEND);
							} else {
								echo "\n\n\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n\n\n".$output."\n\n";
								echo 'composite('.$composite.') != bignumber('.$bignumber.') on line '.__LINE__."\n";
								print_r($entry);
								exit(1);
							}
						} else {
							echo "\n\n\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n\n\n".$output."\n\n";
							echo 'Did not find list of factors in output (err line '.__LINE__.')'."\n";
							exit(1);
						}
					} else {
						echo $errmsg = "\n\n\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n".date('Y-m-d H:i:s')."\n\n".$output."\n\n".'Did not find FACTORS FOUND in output (err line '.__LINE__.')'."\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
						file_put_contents('factordb_errors.log', $errmsg, FILE_APPEND);
						if (preg_match('#(failed to (re)?allocate [0-9]+ bytes|error re\\-allocating in\\-memory storage of relations)#i', $output)) {
							// failed to reallocate 1079470080 bytes
							// failed to allocate 1280 bytes in xmalloc_align
							// error re-allocating in-memory storage of relations
							FactorDB_submit();
							echo "\n\n\n".'Known memory-allocation error message'."\n";
							echo 'Sleeping for '.$CONFIG['sleepseconds'].'s, then continuing next composite'."\n";
							sleep($CONFIG['sleepseconds']);
							continue;
						} else {
							FactorDB_submit();
							echo "\n\n\n".'YAFU aborted for unknown reason... ?'."\n";
							echo 'Sleeping for '.$CONFIG['sleepseconds'].'s, then continuing next composite'."\n";
							sleep($CONFIG['sleepseconds']);
							continue;
							//exit(1);
						}
					}
				} else {
					echo 'Unexpected Input "'.$line.'"'."\n\n";
					exit(1);
				}
			}
			file_put_contents($CONFIG['in_filename'], implode("\n", $lines)."\n");
		}
	} else {
		echo 'UNEXPECTED on line '.__LINE__."\n\n";
		exit(1);
	}
	return true;
}

function FilesCleanup() {
	global $CONFIG;
	$YAFUdir = dirname($CONFIG['yafu_executable']);
	$FilesToCleanUp = array(
		__DIR__.DIRECTORY_SEPARATOR.basename($CONFIG['log_filename']),
		__DIR__.DIRECTORY_SEPARATOR.basename($CONFIG['json_filename']),
		__DIR__.DIRECTORY_SEPARATOR.basename($CONFIG['base10_filename']),
		__DIR__.DIRECTORY_SEPARATOR.basename($CONFIG['in_filename']),
		$YAFUdir.DIRECTORY_SEPARATOR.'session.log',
		$YAFUdir.DIRECTORY_SEPARATOR.'ggnfs.log',
		$YAFUdir.DIRECTORY_SEPARATOR.'siqs.dat',
		$YAFUdir.DIRECTORY_SEPARATOR.'__tmpbatchfile',
	);
	$DirsToScan = array_unique(array(__DIR__, $YAFUdir));
	foreach ($FilesToCleanUp as $filename) {
		if (file_exists($filename)) {
			echo 'Delete: '.$filename."\n";
			unlink($filename);
		}
	}
	foreach (scandir($YAFUdir) as $file) {
		$filename = $YAFUdir.DIRECTORY_SEPARATOR.$file;
		if (preg_match('#^(\\.last_.+|nfs\\..+|.+\\.job|.+\\.out)$#i', $file)) {
			echo 'Delete: '.$filename."\n";
				unlink($filename);
		}
	}
	return true;
}

function CheckForExit($deletefile=false) {
	global $CONFIG;
	if (file_exists('exit.txt')) {
		echo 'exit.txt found, exiting'."\n";
		if ($deletefile) {
			unlink('exit.txt');
		}
		return true;
	}
	return false;
}

/////////////////////////////////////////////////////////////////////

do {
	$submit_counter = FactorDB_submit();
	if (!CheckForExit(false)) {
		PauseWhileRunning();
		if ($sleep_seconds = IsSleepTime()) {
			echo 'Sleep period detected, pausing for '.(($sleep_seconds > 3600) ? number_format($sleep_seconds / 3600, 1).' hours' : (($sleep_seconds > 60) ? number_format($sleep_seconds / 60, 1).' minutes' : number_format($sleep_seconds).' seconds')).' (wake up at '.date('H:i', time() + $sleep_seconds).'h)'."\n";
			sleep($sleep_seconds);
		}
	}
	if (CheckForExit(true)) {
		break;
	}
	while (!file_exists($CONFIG['in_filename']) || (filesize($CONFIG['in_filename']) < 10)) {
		FactorDB_fetch();
		clearstatcache();
		if (!file_exists($CONFIG['in_filename']) || (filesize($CONFIG['in_filename']) < 10)) {
			echo $CONFIG['in_filename'].' too small ('.number_format(filesize($CONFIG['in_filename'])).'), waiting '.$CONFIG['sleepseconds'].' seconds'."\n";
			sleep($CONFIG['sleepseconds']);
		}
	}
	Rate1();
	FactorDB_runbatch();
	Rate2();
} while (true);
FactorDB_submit();
echo 'End Of Loop'."\n\n";
