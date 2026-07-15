<?php
$CONFIG = [
	'yafu_executable' => 'yafu-windows-avx2.exe',
	'api_url'         => 'http://mersenne.localhost/aliquot/index.php',
	'max_digits'      => 92,
	'sleep_seconds'   => 65,
];
define('IS_WINDOWS', (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN'));


function FilesCleanup() {
	global $CONFIG;
	$FilesToCleanUp = array(
		realpath('session.log'),
		realpath('ggnfs.log'),
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

do {
	FilesCleanup();
	if (CheckForExit(true)) {
		break;
	}
	$URL_fetch = $CONFIG['api_url'].'?composites_to_factor=1&max_digits='.$CONFIG['max_digits'].'&gimps_login=JamesHeinrich';
//echo $URL_fetch."\n";
	if ($work = file_get_contents($URL_fetch)) {
//echo $work."\n";
		foreach (explode("\n", $work) as $bignumber) {
			if ($bignumber = trim($bignumber)) {
				if (ctype_digit($bignumber)) {
					$command = (IS_WINDOWS ? '' : 'nice -n 19 ').escapeshellarg($CONFIG['yafu_executable']).' '.escapeshellarg($bignumber);
					$output = '';
//echo $command."\n";
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
//file_put_contents('moo.txt', $output);
//echo 'Looking for "#'.preg_quote('***factorization:***').'[\r\n]+('.$bignumber.'=([0-9\\*]+))[\r\n]+ans = 1($|[\r\n])#sm"'."\n";
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

							file_put_contents('aliquot_factorization.txt', $one_line_factorization.PHP_EOL);
							if ($ch = curl_init()) {
								$data = ['compositefactorization' => $one_line_factorization];
								curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
								curl_setopt($ch, CURLOPT_TIMEOUT,        30);
								curl_setopt($ch, CURLOPT_URL, $CONFIG['api_url']);
								curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
								curl_setopt($ch, CURLOPT_POST, true);
								curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
								curl_setopt($ch, CURLOPT_HEADER, true);
								do {
									$curl_output = curl_exec($ch);
									$info = curl_getinfo($ch);
									if ($info['http_code'] == 200) {
										echo 'Reported '.$bignumber.' to '.$CONFIG['api_url']."\n\n".str_repeat('~', 50)."\n\n";
									} else {
										echo date('Y-m-d H:i:s').' report to '.$CONFIG['api_url'].' did not succeed, trying again in '.$CONFIG['sleep_seconds'].'s'."\n";
										sleep($CONFIG['sleep_seconds']);
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
						echo $errmsg = "\n\n\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n".date('Y-m-d H:i:s')."\n\n".$output."\n\n".'Did not find ***factorization:*** in output (err line '.__LINE__.')'."\n~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
						exit(1);
					}
				} else {
					echo 'Unexpected value in worktodo:'."\n".$bignumber."\n";
					exit(1);
				}
			}
		}

	} else {
		echo date('Y-m-d H:i:s').' No work available (max digits: '.$CONFIG['max_digits'].'), sleeping '.$CONFIG['sleep_seconds'].'s'."\n";
		sleep($CONFIG['sleep_seconds']);
	}
} while (true);
FilesCleanup();
echo '#EndOfScript'."\n";
