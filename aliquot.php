<?php
$CONFIG = [
	'gimps_login'     => '', // ** REQUIRED **
	'yafu_executable' => 'yafu-windows-avx2.exe',
	'min_digits'      =>  90,
	'max_digits'      =>  120,
	'fetch_at_once'   =>  1,
	'sleep_seconds'   =>  65,
	'siqs_nfs_limit'  =>  false, // should normally be FALSE, can set to an integer (e.g. 100) to prevent doing SIQS/NFS on big numbers, only do ECM and SIQS/NFS on composites smaller than this
	'pretest'         =>  false, // should normally be FALSE, can set to TRUE to enable pretest-only pre-factoring of composites
	'pretest_ratio'   =>  0.25,  // only applies for "pretest" mode, for normal use set "plan" in yafu.ini
	'log_factors'     => 'aliquot_factorization.txt', // set to emptystring to disable
	'api_url'         => 'https://www.mersenne.ca/aliquot/index.php',
];
define('IS_WINDOWS', (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN'));


function FilesCleanup() {
	global $CONFIG;
	$FilesToCleanUp = array(
		realpath('session.log'),
		realpath('ggnfs.log'),
		realpath('factor.log'),
		realpath('factor.json'),
		realpath('siqs.dat'),
		realpath('__tmpbatchfile'),
	);
	foreach ($FilesToCleanUp as $filename) {
		if ($filename && file_exists($filename)) {
			echo 'Delete: '.$filename."\n";
			unlink($filename);
		}
	}
	foreach (scandir(__DIR__) as $file) {
		$filename = realpath($file);
		if (preg_match('#^(\\.last_.+|nfs\\..+|.+\\.job|.+\\.out)$#i', $file)) {
			echo 'Delete: '.$filename."\n";
			unlink($filename);
		}
	}
	return true;
}

function CheckForExit($deletefile=false) {
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
if (empty($CONFIG['gimps_login'])) {
	die('$CONFIG[gimps_login] is empty');
}

do {
	FilesCleanup();
	if (CheckForExit(true)) {
		break;
	}
	$URL_fetch = $CONFIG['api_url'].'?'.($CONFIG['pretest'] ? 'composites_to_pretest' : 'composites_to_factor').'='.$CONFIG['fetch_at_once'].'&min_digits='.$CONFIG['min_digits'].'&max_digits='.$CONFIG['max_digits'].'&gimps_login='.$CONFIG['gimps_login'];
	if ($work = file_get_contents($URL_fetch)) {
		foreach (explode("\n", $work) as $bignumber) {
			if ($bignumber = trim($bignumber)) {
				if (ctype_digit($bignumber)) {
					$command  = (IS_WINDOWS ? '' : 'nice -n 19 ');
					$command .= escapeshellarg($CONFIG['yafu_executable']);
					$command .= ' '.escapeshellarg($bignumber);
					$command .= ' -terse';
					$command .= ($CONFIG['siqs_nfs_limit'] ? ' -max_siqs '.intval($CONFIG['siqs_nfs_limit']).' -max_nfs '.intval($CONFIG['siqs_nfs_limit']) : '');
					$command .= ($CONFIG['pretest'] ? ' -pretest -plan custom -pretest_ratio '.number_format($CONFIG['pretest_ratio'], 4) : '');

					$output = '';
					if ($pipe = popen($command, 'rb')) {
						while ($buffer = fread($pipe, 1024)) { // buffer smaller than 1024 might not get all the data we need at once
							$output .= $buffer;
/*
							// total yield: 17800, q=1122001 (0.00043 sec/rel)
							$buffer = preg_replace('#(total yield: [0-9]+, q=[0-9]+, \([0-9\.]+ sec/rel\))([\r\n]+)#', '$1'."\r", $buffer);
							//nfs: commencing algebraic side lattice sieving over range: 764000 - 766000
							$buffer = preg_replace('#(nfs: commencing algebraic side lattice sieving over range: [0-9]+ \- [0-9]+)([\r\n]+)#', '$1'."\r", $buffer);
*/
							echo $buffer;
						}
						pclose($pipe);
					} else {
						echo 'FAIL line '.__LINE__."\n";
						exit(1);
					}
//echo 'Looking for "#'.preg_quote('***factorization:***').'[\r\n]+('.$bignumber.'=([0-9\\*]+))[\r\n]+ans = 1($|[\r\n])#sm"'."\n";
					if (preg_match('#'.preg_quote('***factorization:***').'[\r\n]+(([0-9]+)=([0-9\\*]+))[\r\n]+ans = ([0-9]+)($|[\r\n])#sm', $output, $matches)) {
						// one-line factorization output (optional) added in YAFU 3.0
						// could just use it verbatim but may as well take the short time to verify that the listed factors add up (on rare occasion YAFU has had an error where they do not match)
						list($dummy, $one_line_factorization, $one_line_bignumber, $factorlist, $remainder) = $matches;
						if ($bignumber == $one_line_bignumber) { // check again YAFU errors (e.g. https://www.mersenneforum.org/node/1108401?p=1120597#post1120597 or https://www.mersenneforum.org/node/1108401?p=1121395#post1121395)
							if (($remainder != '1') && ($CONFIG['pretest'] !== true)) {
								echo "\n\n\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n\n\n".$output."\n\n";
								echo 'remainder('.$remainder.') != 1 on line '.__LINE__."\n";
								print_r($matches);
								exit(1);
							}
							$composite = 1;
							$factors = explode('*', $matches[2]);
							foreach ($factors as $factor) {
								$composite = gmp_mul($composite, $factor);
							}
							if (gmp_strval($composite) == $bignumber) {

								if ($CONFIG['log_factors']) {
									file_put_contents($CONFIG['log_factors'], $one_line_factorization.PHP_EOL, FILE_APPEND);
								}
								if ($ch = curl_init()) {
									$data = [
										'compositefactorization' => (string) $one_line_factorization,
										'gimps_login'            => (string) $CONFIG['gimps_login'],
									];
									if ($CONFIG['pretest']) {
										$data['pretest_ratio'] = (float) $CONFIG['pretest_ratio'];
									}
									curl_setopt($ch, CURLOPT_CONNECTTIMEOUT,      10);
									curl_setopt($ch, CURLOPT_TIMEOUT,             30);
									curl_setopt($ch, CURLOPT_URL, $CONFIG['api_url']);
									curl_setopt($ch, CURLOPT_RETURNTRANSFER,       1);
									curl_setopt($ch, CURLOPT_POST,              true);
									curl_setopt($ch, CURLOPT_POSTFIELDS,       $data);
									curl_setopt($ch, CURLOPT_HEADER,           false);
									do {
										$curl_output = curl_exec($ch);
										$info = curl_getinfo($ch);
										if ($info['http_code'] == 200) {
											$JSON = json_decode($curl_output, true);
											if (json_last_error() == JSON_ERROR_NONE) {
												if (!empty($JSON['warning'])) {
													print_r($JSON['warning']);
												}
												if (!empty($JSON['error'])) {
													print_r($JSON['error']);
												}
											} else {
var_dump($curl_output);
var_dump($info);
												echo date('Y-m-d H:i:s').' '.$CONFIG['api_url'].' did not return valid JSON'."\n";
												echo date('Y-m-d H:i:s').' trying again in '.$CONFIG['sleep_seconds'].'s'."\n";
												sleep($CONFIG['sleep_seconds']);
//exit;
											}
											echo 'Reported C'.strlen($bignumber).' '.$bignumber.' to '.$CONFIG['api_url']."\n\n".str_repeat('~', 50)."\n\n";
										} elseif ($info['http_code'] == 0) {
											echo date('Y-m-d H:i:s').' report to '.$CONFIG['api_url'].' did not succeed, trying again in '.$CONFIG['sleep_seconds'].'s'."\n";
											sleep($CONFIG['sleep_seconds']);
										} elseif ($info['http_code'] == 503) {
											echo date('Y-m-d H:i:s').' server down for maintenance, trying again in '.$CONFIG['sleep_seconds'].'s'."\n";
											sleep($CONFIG['sleep_seconds']);
										} else {
echo 'Unexpected CURL http_code='.$info['http_code']."\n";
var_dump($curl_output);
var_dump($info);
exit;
										}
									} while ($info['http_code'] != 200);
								} else {
									echo 'FAIL: curl_init() error line '.__LINE__."\n";
									exit(1);
								}

							} else {
								echo "\n\n\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n\n\n".$output."\n\n";
								echo 'composite('.$composite.') != bignumber('.$bignumber.') on line '.__LINE__."\n";
								print_r($matches);
								exit(1);
							}
						} else {
							echo $errmsg = "\n\n\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n".date('Y-m-d H:i:s')."\n\n".$output."\n\n".'YAFU error: ***factorization*** output composite does not match input composite (err line '.__LINE__.')'."\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
							exit(1);
						}
					} else {
						echo $errmsg = "\n\n\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n".date('Y-m-d H:i:s')."\n\n".$output."\n\n".'Did not find ***factorization:*** in output (err line '.__LINE__.')'."\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
						exit(1);
					}
				} else {
					echo $URL_fetch."\n";
					echo 'Unexpected value in worktodo:'."\n".$bignumber."\n";
					exit(1);
				}
			}
		}

	} else {
		echo date('Y-m-d H:i:s').' No work available ('.$CONFIG['min_digits'].'-'.$CONFIG['max_digits'].' digits), sleeping '.$CONFIG['sleep_seconds'].'s'."\n";
		sleep($CONFIG['sleep_seconds']);
	}
} while (true);
FilesCleanup();
echo '#EndOfScript'."\n";
