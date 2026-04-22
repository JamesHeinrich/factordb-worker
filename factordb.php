<?php
// factordb.com work fetch/submit script
// James Heinrich <james@mersenne.ca>
// https://www.mersenneforum.org/node/22384
// last-modified: 2026-04-22

$configFileName = 'factordb.json';
$CONFIG = array();
$configJSONtext = '';
if (is_readable($configFileName)) {
	if ($configJSONtext = trim(file_get_contents($configFileName))) {
		if ((substr($configJSONtext, 0, 1) == '{') && (substr($configJSONtext, -1, 1) == '}')) {
			$CONFIG = json_decode($configJSONtext, true);
		}
	}
}
define('IS_WINDOWS', (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN'));
$configDefaults = array(
	'min_digits'          => 90,    // minimum number of digits for composites we want to factor
	'max_digits'          => 100,   // maximum number of digits for composites we want to factor, if no work is available smaller than this then sit idle for <sleepseconds>
	'skip_first'          => 1234,  // skip the smallest X composites, other people will likely grab them before you can return them
	'batch_time'          => 600,   // target number of seconds for a batch of assignments, rate will be auto-adjusted to attempt to meet this
	'sleepseconds'        => 300,   // number of seconds to sleep between retries if factordb.com does not respond as expected for get work or submit results
	'txtfile'             => __DIR__.DIRECTORY_SEPARATOR.'yafu-submissions_YYYYMMDD.txt',        // copy-append simplest factorization lines to this file after submitting each batch of results, YYYYMMDDHHMMSS will be replaced with today's datetimestamp or YYYYMMDD will be replaced with today's datestamp
	'yafu_executable'     => __DIR__.DIRECTORY_SEPARATOR.'yafu'.(IS_WINDOWS ? '-x64.exe' : ''),
	'cookie_jar'          => __DIR__.DIRECTORY_SEPARATOR.'cookies.txt',
	'in_filename'         => __DIR__.DIRECTORY_SEPARATOR.'random_composites.txt',
	//'composite_uniquelog' => __DIR__.DIRECTORY_SEPARATOR.'composites_unique.log',
	'log_filename'        => __DIR__.DIRECTORY_SEPARATOR.'factor.log',
	'json_filename'       => __DIR__.DIRECTORY_SEPARATOR.'factor.json',
	'base10_filename'     => __DIR__.DIRECTORY_SEPARATOR.'factor.txt',
	'rate_filename'       => __DIR__.DIRECTORY_SEPARATOR.'factordb.rate',
	'sleep_during'        => '',    // optional, script will pause during these times, format "00:00-08:00;16:00-23:59" (etc)
	'pause_while_running' => '',    // optional, script will pause if these program running, format "photoshop.exe;prime95.exe" (etc) substring match, case-insensitive; currently only implemented for Windows
	'login_user'          => '',    // factordb.com username, without this your submission rate will be limited
	'login_pass'          => '',    // factordb.com password, without this your submission rate will be limited
);
foreach ($configDefaults as $key => $value) {
	if (!isset($CONFIG[$key])) {
		$CONFIG[$key] = $value;
	}
}
$configJSONtextNew = trim(json_encode($CONFIG, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($configJSONtextNew != $configJSONtext) {
	file_put_contents($configFileName, $configJSONtextNew);
}


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

function FactorDB_login() {
	global $CONFIG;
	if ($CONFIG['login_user'] && $CONFIG['login_pass'] && $CONFIG['cookie_jar']) {
		if ($ch = curl_init()) {
			$data = array(
				'user'   => $CONFIG['login_user'],
				'pass'   => $CONFIG['login_pass'],
				'dlogin' => 'Login',
			);
			curl_setopt($ch, CURLOPT_URL, 'http://factordb.com/login.php');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_COOKIEJAR, $CONFIG['cookie_jar']);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			curl_setopt($ch, CURLOPT_HEADER, true);

			do { // container loop to catch "SQLSTATE[HY000] [2002] Connection refused" errors
				do {
					$output = curl_exec($ch);
					$info = curl_getinfo($ch);
					if ($info['http_code'] != 200) {
						echo date('c').' Login failed: curl_getinfo[http_code]='.$info['http_code'].' (expected: 200). Sleeping for '.$CONFIG['sleepseconds'].' seconds'."\n";
						sleep($CONFIG['sleepseconds']);
						continue;
					}
				} while ($info['http_code'] != 200);

				$head = substr($output, 0, $info['header_size']);
				$body = substr($output, $info['header_size']);
				if (preg_match('#^Set-Cookie: +fdbuser=([0-9a-f]{32});#m', $head, $matches)) {
					// Set-Cookie: fdbuser=6f162a76ac69abebc7b94cd19d1b8ccd; expires=Tue, 01 Feb 2028 20:52:55 GMT; Max-Age=96000000
					$sessionid = $matches[1];
					echo $matches[0]."\n";
					break;
				} else {
					echo "\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n".$output."\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
					echo date('c').' Failed to find "Set-Cookie: fdbuser" on CURL login'."\n";
					echo date('c').' Sleeping for '.$CONFIG['sleepseconds'].' seconds...'."\n";
					sleep($CONFIG['sleepseconds']);
				}
			} while (true);

			do { // container loop to catch "parallel processing" errors
				if (preg_match('#Logged in as \\<b\\>([^\\<]+)\\</b\\>#i', $output, $matches)) {
					list($dummy, $logged_in_username) = $matches;
					echo date('c').' Logged in as "'.$logged_in_username.'" (session ID: '.$sessionid.') [login overhead: '.number_format($info['total_time'], 3).'s]'."\n";
					break;
				} else {
					if (preg_match('#You have reached the maximum of [0-9]+ parallel processing requests\\. +Please wait a few seconds and try again\\.#i', $output, $matches)) {
						echo date('c').' '.$matches[0]."\n";
						echo date('c').' Sleeping for '.$CONFIG['sleepseconds'].' seconds...'."\n";
						sleep($CONFIG['sleepseconds']);
					} else {
						echo "\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n".$output."\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
						echo 'Failed to find "Logged in as [username]" on CURL login'."\n";
						exit(1);
					}
				}
			} while (true);
		}
	}
	return true;
}

if (!empty($_SERVER['argv'][1])) {
	if ($_SERVER['argv'][1] == 'submit') {
		FactorDB_login();
		FactorDB_submit();
	} elseif ($_SERVER['argv'][1] == 'fetch') {
		FactorDB_login();
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
	return file_put_contents($CONFIG['rate_filename'], date('c')."\t".$assignmentCount);
}
function Rate2() {
	global $CONFIG;
	$assignmentCount = (($raw = trim(@file_get_contents($CONFIG['in_filename']))) ? count(explode("\n", $raw)) : 0);
	return file_put_contents($CONFIG['rate_filename'], "\n".date('c')."\t".$assignmentCount, FILE_APPEND);
}
function AvgRate($avg, $count) {
	global $CONFIG;
	return file_put_contents($CONFIG['rate_filename'], date('c', time() - ceil($avg * $count))."\t".$count."\n".date('c')."\t".'0');
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
	$check_pause_seconds = 60;

	if (!IS_WINDOWS) {
		// below code only works for Windows
		return false;
	}
	if (!empty($CONFIG['pause_while_running'])) {
		if ($lastCheckedTime) {
			if ($lastCheckedTime > (microtime(true) - $check_pause_seconds)) {
echo 'PauseWhileRunning() checked recently ('.number_format(microtime(true) - $lastCheckedTime, 3).'s ago), skipping current check'."\n\n\n";
				return false;
			}
		}
//echo 'PauseWhileRunning() not checked recently ('.($lastCheckedTime ? number_format(microtime(true) - $lastCheckedTime, 3).'s ago' : 'never').'), performing current check'."\n\n\n";
		$lastCheckedTime = microtime(true);
		$paused_because = '';
		$submitted_results = false;
		do {
			/*
			TASKLIST only shows basename (e.g. "notepad.exe") does not include path information
			If need to process paths can consider something like:
			WMIC PROCESS WHERE "CommandLine LIKE '%steamapps%'" GET COMMANDLINE
			This may be a bit slower, haven't really tested
			*/
			if (CheckForExit(false)) {
				break;
			}
			$command = 'tasklist /FO CSV';
			if ($tasklist = shell_exec($command)) {
				$found_programs = array();
				foreach (explode(';', $CONFIG['pause_while_running']) as $process) {
					if (stripos($tasklist, $process) !== false) {
						$found_programs[$process] = $process;
						if (!$submitted_results) {
							FactorDB_submit();
							$submitted_results = true;
						}
					}
				}
				if (!empty($found_programs)) {
					if (empty($found_programs[$paused_because])) {
						foreach ($found_programs as $process) {
							echo "\n".date('c').' Paused because "'.$process.'" is running (checking every '.$check_pause_seconds.' seconds)'."\n";
							$paused_because = $process;
							break;
						}
					}
					sleep($check_pause_seconds);
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

function curlGEThttp($URL, $description='') {
	global $CONFIG;
	if ($ch = curl_init()) {
		curl_setopt($ch, CURLOPT_URL, $URL);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		if (strtolower(substr($URL, 0, 5)) == 'https') {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		}
		if (file_exists($CONFIG['cookie_jar']) && filesize($CONFIG['cookie_jar'])) {
			curl_setopt($ch, CURLOPT_COOKIEFILE, $CONFIG['cookie_jar']);
		}
		$output = curl_exec($ch);
		$info = curl_getinfo($ch);
		do {
			$output = curl_exec($ch);
			$info = curl_getinfo($ch);
			if ($info['http_code'] != 200) {
				date('Y-m-d H:i:s').' error '.__LINE__.': '.$description.' failed: curl_getinfo[http_code]='.$info['http_code'].' (expected: 200). Sleeping for '.$CONFIG['sleepseconds'].' seconds'."\n";
				sleep($CONFIG['sleepseconds']);
				continue;
echo "\n".'DEBUG '.__FUNCTION__.':'.__LINE__."\n";
echo "~~~~~~~~~~~~~~~\n".$output."\n~~~~~~~~~~~~~~~~~~~~~~~~~\n";
			}
		} while ($info['http_code'] != 200);
		return $output;
	}
	echo date('Y-m-d H:i:s').' error '.__LINE__.': Failed to curl_init()'."\n\n";
	exit(1);
	return false;
}

function FactorDB_fetch() {
	global $CONFIG;
	$CONFIG['_last_fetch_time'] = time(); // not really a config setting, but a convenient already-global variable

	$number_to_grab     = 50; // assume fetch 50 assignments if we have no rate data
	$max_number_to_grab = 50; // do not allow batch sizes larger than this; factordb.com sometimes times out if you try to grab too many at once
	if (is_readable($CONFIG['rate_filename']) && ($rateRaw = trim(@file_get_contents($CONFIG['rate_filename'])))) {
		if (preg_match('#^(.+)\\t([0-9]+)[\\r\\n]+(.+)\\t([0-9]+)$#', $rateRaw, $matches)) {
			list($dummy, $date1, $count1, $date2, $count2) = $matches;
			$seconds = strtotime($date2) - strtotime($date1);
			$seconds_per = $seconds / max($count1 - $count2, 1);
			$number_to_grab = max(ceil($CONFIG['batch_time'] / max($seconds_per, 0.1)), 1);

			if ($number_to_grab > $max_number_to_grab) {
				echo date('Y-m-d H:i:s').' Target batch size for '.$CONFIG['batch_time'].'s is '.$number_to_grab.', but limiting to '.$max_number_to_grab.' for server reasons'."\n";
			}
			$number_to_grab = min($max_number_to_grab, $number_to_grab);
			echo date('Y-m-d H:i:s').' Completed '.($count1 - $count2).' assignments in '.$seconds.' seconds, '.number_format($seconds_per, 3).'s avg, grabbing '.$number_to_grab.' new assignments'."\n";
		}
	}

	// http://factordb.com/listtype.php?t=3&download=1&mindig=80&start=12345&perpage=100
	$URL  = 'http://factordb.com/listtype.php?t=3';
	$URL .= '&download=1';
	$URL .= '&mindig='.$CONFIG['min_digits'];
	$URL .= '&start='.($CONFIG['skip_first'] ?: 0);
	$URL .= '&perpage='.$number_to_grab;
	echo date('Y-m-d H:i:s').' Fetching '.$number_to_grab.' new assignments from '.$URL."\n";
	$output = curlGEThttp($URL, 'Work fetch'); // plaintext output contains \n lineends
//echo '$output = '.strlen($output).' bytes'."\n";
//echo '~~~~~~~~~~~~~~~~~~~~~~~~~~'."\n";
//echo $output."\n";
//echo '~~~~~~~~~~~~~~~~~~~~~~~~~~'."\n";
	$allWorkToDo     = array();
	$allWorkToDoTemp = array();
	foreach (explode("\n", str_replace("\r", '', trim(@file_get_contents($CONFIG['in_filename'])."\n".$output))) as $line) {
		// filter out any non-assignment data (e.g. error messages from server)
		// also eliminate any duplicate entries
		if (($composite = trim($line)) && ctype_digit($composite)) {
			if (strlen($composite) >= $CONFIG['min_digits']) {
				$allWorkToDoTemp[$composite] = log($composite, 2);
			} else {
				echo 'shorter than C'.$CONFIG['min_digits'].': '.$composite."\n";
			}
		}
	}
	asort($allWorkToDoTemp, SORT_STRING);
	$allWorkToDo = array_keys($allWorkToDoTemp);
	echo date('Y-m-d H:i:s').' '.basename($CONFIG['in_filename']).' now has '.number_format(count($allWorkToDo)).' assignments'."\n";
	if (count($allWorkToDo)) {
		file_put_contents($CONFIG['in_filename'], trim(implode("\n", $allWorkToDo))."\n"); // bug: YAFU v2.10 ignores last line in input file if it doesn't have a linebreak after
	} else {
		file_put_contents($CONFIG['in_filename'], ''); // set filesize to zero to prevent confusion/conflict
	}
	return true;
}

function FactorDB_submit() {
	global $CONFIG;

	$result_lines = array();  // parse results.json into composite=prime1*prime2
	$runtimes     = array();  // actual runtimes from JSON results
	$SubmittedComposites = array();
	if (file_exists($CONFIG['base10_filename']) && filesize($CONFIG['base10_filename']))  {
		foreach (explode("\n", file_get_contents($CONFIG['base10_filename'])) as $linecounter => $line) {
			if ($line = trim($line)) {
				if (preg_match('#^([0-9]+)=([0-9\\*]+)$#', $line, $matches)) {
					list($dummy, $composite, $factorslist) = $matches;
					$result_lines['c'.sprintf('%03d', strlen($composite)).'_'.$composite] = $composite.'='.$factorslist;
					$SubmittedComposites[$composite] = 1;
				}
			}
		}
	} elseif (file_exists($CONFIG['json_filename']) && filesize($CONFIG['json_filename']))  {
		foreach (explode("\n", file_get_contents($CONFIG['json_filename'])) as $linecounter => $line) {
			if ($line = trim($line)) {
				if ((substr($line, 0, 1) == '{') && (substr($line, -1, 1) == '}')) {
					$decoded = json_decode($line, true, 512, JSON_BIGINT_AS_STRING);
					if (json_last_error() == JSON_ERROR_NONE) {
						if (!empty($decoded['runtime']['total'])) {
							$runtimes[] = $decoded['runtime']['total'];
						}

						$output  = $decoded['input-decimal'].'=';
						if (!empty($decoded['factors-prime'])) {
							$output .= implode('*', $decoded['factors-prime']);
						}
						if (!empty($decoded['factors-composite'])) {
							$output .= (!empty($decoded['factors-prime']) ? '*' : '').implode('*', $decoded['factors-composite']);
						}
						$result_lines['c'.sprintf('%03d', strlen($decoded['input-decimal'])).'_'.$decoded['input-decimal']] = $output;
						$SubmittedComposites[$decoded['input-decimal']] = 1;
					} else {
						echo 'JSON decode error:'."\n".$line."\n\n";
						exit(1);
					}
				} else {
					echo 'Unexpected non-JSON line['.($linecounter + 1).']:'."\n".$line."\n\n";
					exit(1);
				}
			}
		}
	}
	if ($submit_counter = count($result_lines)) {
		if (!empty($runtimes)) {
			AvgRate(array_sum($runtimes) / count($runtimes), count($runtimes));
		}
		ksort($result_lines);

		file_put_contents('results_submission_mostrecent.html', '');
		$submit_slice_offset =  0;
		$submit_slice_size   = 50;

		for ($submit_slice_offset = 0; $submit_slice_offset < count($result_lines); $submit_slice_offset += $submit_slice_size) {
			$slice = array_slice($result_lines, $submit_slice_offset, $submit_slice_size, true);
			$submit_counter = count($slice);

			//$result_lines_text = implode("\n", $result_lines)."\n";
			$result_lines_text = implode("\n", $slice)."\n";
			$data = array(
				'report' => $result_lines_text,
				'format' =>  7, // Multiple factors per line, base 10
			);
			if ($ch = curl_init()) {
				// https://electrictoolbox.com/php-curl-form-post/
				$ReportURL = 'http://factordb.com/report.php';
				curl_setopt($ch, CURLOPT_URL, $ReportURL);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				if (file_exists($CONFIG['cookie_jar']) && filesize($CONFIG['cookie_jar'])) {
					curl_setopt($ch, CURLOPT_COOKIEFILE, $CONFIG['cookie_jar']);
				}
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
echo $result_lines_text;
				do {
		    		echo date('Y-m-d H:i:s').' Submitting '.($submit_counter ? number_format($submit_counter) : 'UNKNOWN NUMBER') .' results ('.number_format(strlen($result_lines_text)).' bytes) to '.$ReportURL."\n";
					$output = curl_exec($ch);
					$info = curl_getinfo($ch);
					if ($info['http_code'] != 200) {
						echo date('Y-m-d H:i:s').' Submit Results failed: curl_getinfo[http_code]='.$info['http_code'].' (expected: 200). Sleeping for '.$CONFIG['sleepseconds'].' seconds'."\n";
echo "\n".'DEBUG '.__FUNCTION__.':'.__LINE__."\n";
echo "~~~~~~~~~~~~~~~\n".$output."\n~~~~~~~~~~~~~~~~~~~~~~~~~\n";
						sleep($CONFIG['sleepseconds']);
						continue;
					}
				} while ($info['http_code'] != 200);
				$output = preg_replace('#<a href="(?!https?:\/\/)#', '<a href="https://factordb.com/', $output);
				//file_put_contents('results_submission_'.date('Ymd-His').'.html', $output);
				file_put_contents('results_submission_mostrecent.html', $output, FILE_APPEND);

				if (preg_match('#Found ([0-9]+) factors and [0-9]+ ECM#', $output, $matches)) {
					// Found 122 factors and 0 ECM/P-1/P+1 results.
					echo 'Server accepted '.$matches[1].' factors from '.$submit_counter.' factorizations ('.number_format(($matches[1] / $submit_counter) * 100).'%)'."\n";
				} else {
					file_put_contents('results_submission_INCOMPLETE_'.date('Ymd-His').'.html', $output);
					file_put_contents('results_submission_INCOMPLETE_'.date('Ymd-His').'.txt', $result_lines_text);
echo "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n";
echo 'Server did not accept all results!'."\n";
echo 'Partially-accepted submission data saved to "results_submission_INCOMPLETE_'.date('Ymd-His').'.txt"'."\n";
echo "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n";
				}
			} else {
				date('Y-m-d H:i:s').' error '.__LINE__.': Failed to curl_init()'."\n\n";
				exit(1);
			}
		}
		if ($CONFIG['txtfile'] && !empty($result_lines_text)) {
			file_put_contents(str_replace('YYYYMMDD', date('Ymd'), str_replace('YYYYMMDDHHMMSS', date('YmdHis'), $CONFIG['txtfile'])), $result_lines_text, FILE_APPEND);
		}
	}
	FilesCleanup();
	return $submit_counter;
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
					if (preg_match('#'.preg_quote('***factorization:***').'[\r\n]+('.$bignumber.'=([0-9\\*]+))[\r\n]+ans = 1$#sm', $output, $matches)) {
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
						echo $errmsg = "\n\n\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n".date('c')."\n\n".$output."\n\n".'Did not find FACTORS FOUND in output (err line '.__LINE__.')'."\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
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
	FactorDB_login();
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
