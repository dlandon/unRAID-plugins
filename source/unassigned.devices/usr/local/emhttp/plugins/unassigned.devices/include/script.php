<?
$plugin = "unassigned.devices";
require_once("plugins/${plugin}/include/lib.php");
readfile('logging.htm');

function write_log($string) {
	if (empty($string)) {
		return;
	}
	$string = str_replace("\n", "<br>", $string);
	$string = str_replace('"', "\\\"", trim($string));
	echo "<script>addLog(\"{$string}\");</script>";
	@flush();
}

if ( isset($_GET['device']) && isset($_GET['owner']) ) {
	$device = trim(urldecode($_GET['device']));
	$info = get_partition_info($device, true);
	$owner = trim(urldecode($_GET['owner']));
	$command = execute_script($info, 'ADD', TRUE);
	if ($command != "") {
		$command = $command." 2>&1";
		putenv("OWNER=${owner}");
		write_log($command."<br><br>");
		$proc = popen($command, 'r');
		while (!feof($proc)) {
			write_log(fgets($proc));
		}
	} else {
		echo "No script file to execute!";
	}
}
?>
