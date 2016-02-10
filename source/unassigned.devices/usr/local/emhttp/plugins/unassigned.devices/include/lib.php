<?
$plugin = "unassigned.devices";
// $VERBOSE=TRUE;

$paths =  array("smb_extra"       => "/boot/config/smb-extra.conf",
				"smb_usb_shares"  => "/etc/samba/unassigned-shares",
				"usb_mountpoint"  => "/mnt/disks",
				"device_log"      => "/var/log/",
				"log"             => "/var/log/{$plugin}.log",
				"config_file"     => "/boot/config/plugins/{$plugin}/{$plugin}.cfg",
				"state"           => "/var/state/${plugin}/${plugin}.ini",
				"hdd_temp"        => "/var/state/${plugin}/hdd_temp.json",
				"samba_mount"     => "/boot/config/plugins/${plugin}/samba_mount.cfg",
				"reload"          => "/var/state/${plugin}/reload.state",
				"unmounting"      => "/var/state/${plugin}/unmounting_%s.state",
				"mounting"        => "/var/state/${plugin}/mounting_%s.state",
				);

if (! is_dir(dirname($paths["state"])) ) {
	@mkdir(dirname($paths["state"]),0777,TRUE);
}

if (! isset($var)){
	if (! is_file("/usr/local/emhttp/state/var.ini")) shell_exec("wget -qO /dev/null localhost:$(ss -napt|grep emhttp|grep -Po ':\K\d+')");
	$var = @parse_ini_file("/usr/local/emhttp/state/var.ini");
}

if (! isset($users)){
	$users = @parse_ini_file("/usr/local/emhttp/state/users.ini", true);
}

if (! isset($disks)){
	$disks = @parse_ini_file("/usr/local/emhttp/state/disks.ini", true);
}

#########################################################
#############        MISC FUNCTIONS        ##############
#########################################################

function _echo($m) { echo "<pre>".print_r($m,TRUE)."</pre>";}; 

function save_ini_file($file, $array) {
	$res = array();
	foreach($array as $key => $val) {
		if(is_array($val)) {
			$res[] = PHP_EOL."[$key]";
			foreach($val as $skey => $sval) $res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
		} else {
			$res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
		}
	}
	file_put_contents($file, implode(PHP_EOL, $res));
}

function unassigned_log($m, $type = "NOTICE") {
	if ($type == "DEBUG" && ! $GLOBALS["VERBOSE"]) return NULL;
	$m = date("M d H:i:s")." ".print_r($m,true)."\n";
	file_put_contents($GLOBALS["paths"]["log"], $m, FILE_APPEND);
}

function listDir($root) {
	$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, 
			RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD);
	$paths = array();
	foreach ($iter as $path => $fileinfo) {
		if (! $fileinfo->isDir()) $paths[] = $path;
	}
	return $paths;
}

function safe_name($string) {
	$string = str_replace("\\x20", " ", $string);
	$string = htmlentities($string, ENT_QUOTES, 'UTF-8');
	$string = preg_replace('~&([a-z]{1,2})(acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', $string);
	$string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');
	$string = preg_replace('~[^0-9a-z -_]~i', '', $string);
	$string = preg_replace('~[-_]~i', ' ', $string);
	return trim($string);
}

function exist_in_file($file, $val) {
	return (preg_grep("%${val}%", @file($file))) ? TRUE : FALSE;
}

function is_disk_running($dev) {
	$state = trim(shell_exec("hdparm -C $dev 2>/dev/null| grep -c standby"));
	return ($state == 0) ? TRUE : FALSE;
}

function lsof($dir) {
	return intval(trim(shell_exec("lsof '{$dir}' 2>/dev/null|grep -c -v COMMAND")));
}

function get_temp($dev) {
	$tc = $GLOBALS["paths"]["hdd_temp"];
	$temps = is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
	if (isset($temps[$dev]) && (time() - $temps[$dev]['timestamp']) < 120 ) {
		return $temps[$dev]['temp'];
	} else if (is_disk_running($dev)) {
		$temp = trim(shell_exec("smartctl -A -d sat,12 $dev 2>/dev/null| grep -m 1 -i Temperature_Celsius | awk '{print $10}'"));
		if (! is_numeric($temp)) {
			$temp = trim(shell_exec("smartctl -A -d sat,12 $dev 2>/dev/null| grep -m 1 -i Airflow_Temperature | awk '{print $10}'"));
		}
		$temp = (is_numeric($temp)) ? $temp : "*";
		$temps[$dev] = array('timestamp' => time(),
							 'temp'      => $temp);
		file_put_contents($tc, json_encode($temps));
		return $temp;
	} else {
		return "*";
	}
}

function verify_precleared($dev) {
	$cleared        = TRUE;
	$disk_blocks    = intval(trim(exec("blockdev --getsz $dev  | awk '{ print $1 }'")));
	$max_mbr_blocks = hexdec("0xFFFFFFFF");
	$over_mbr_size  = ( $disk_blocks >= $max_mbr_blocks ) ? TRUE : FALSE;
	$pattern        = $over_mbr_size ? array("00000", "00000", "00002", "00000", "00000", "00255", "00255", "00255") : 
									   array("00000", "00000", "00000", "00000", "00000", "00000", "00000", "00000");

	$b["mbr1"] = trim(shell_exec("dd bs=446 count=1 if=$dev 2>/dev/null        |sum|awk '{print $1}'"));
	$b["mbr2"] = trim(shell_exec("dd bs=1 count=48 skip=462 if=$dev 2>/dev/null|sum|awk '{print $1}'"));
	$b["mbr3"] = trim(shell_exec("dd bs=1 count=1  skip=450 if=$dev 2>/dev/null|sum|awk '{print $1}'"));
	$b["mbr4"] = trim(shell_exec("dd bs=1 count=1  skip=511 if=$dev 2>/dev/null|sum|awk '{print $1}'"));
	$b["mbr5"] = trim(shell_exec("dd bs=1 count=1  skip=510 if=$dev 2>/dev/null|sum|awk '{print $1}'"));

	foreach (range(0,15) as $n) {
		$b["byte{$n}"] = trim(shell_exec("dd bs=1 count=1 skip=".(446+$n)." if=$dev 2>/dev/null|sum|awk '{print $1}'"));
		$b["byte{$n}h"] = sprintf("%02x",$b["byte{$n}"]);
	}

	unassigned_log("Verifying '$dev' for preclear signature.", "DEBUG");

	if ( $b["mbr1"] != "00000" || $b["mbr2"] != "00000" || $b["mbr3"] != "00000" || $b["mbr4"] != "00170" || $b["mbr5"] != "00085" ) {
		unassigned_log("Failed test 1: MBR signature not valid.", "DEBUG"); 
		$cleared = FALSE;
	}
	# verify signature
	foreach ($pattern as $key => $value) {
		if ($b["byte{$key}"] != $value) {
			unassigned_log("Failed test 2: signature pattern $key ['$value'] != '".$b["byte{$key}"]."'", "DEBUG");
			$cleared = FALSE;
		}
	}
	$sc = hexdec("0x{$b[byte11h]}{$b[byte10h]}{$b[byte9h]}{$b[byte8h]}");
	$sl = hexdec("0x{$b[byte15h]}{$b[byte14h]}{$b[byte13h]}{$b[byte12h]}");
	switch ($sc) {
		case 63:
		case 64:
			$partition_size = $disk_blocks - $sc;
			break;
		case 1:
			if ($over_mbr_size) {
				unassigned_log("Failed test 3: start sector ($sc) is invalid.", "DEBUG");
				$cleared = FALSE;
			}
			break;
		default:
			unassigned_log("Failed test 4: start sector ($sc) is invalid.", "DEBUG");
			$cleared = FALSE;
			break;
	}
	if ( $partition_size != $sl ) {
		unassigned_log("Failed test 5: disk size doesn't match.", "DEBUG");
		$cleared = FALSE;
	}
	return $cleared;
}

function get_format_cmd($dev, $fs) {
	switch ($fs) {
		case 'xfs':
			return "/sbin/mkfs.xfs {$dev}";
			break;
		case 'ntfs':
			return "/sbin/mkfs.ntfs -Q {$dev}";
			break;
		case 'btrfs':
			return "/sbin/mkfs.btrfs {$dev}";
			break;
		case 'ext4':
			return "/sbin/mkfs.ext4 {$dev}";
			break;
		case 'exfat':
			return "/sbin/mkfs.exfat {$dev}";
			break;
		case 'fat32':
			return "/sbin/mkfs.fat -s 8 -F 32 {$dev}";
			break;
		default:
			return false;
			break;
	}
}

function format_partition($partition, $fs) {
	$part = get_partition_info($partition);
	if ( $part['fstype'] && $part['fstype'] != "precleared" ) {
		unassigned_log("Aborting format: partition '{$partition}' is already formatted with '{$part[fstype]}' filesystem.");
		return NULL;
	}
	unassigned_log("Formatting partition '{$partition}' with '$fs' filesystem.");
	shell_exec(get_format_cmd($partition, $fs));
	$disk = preg_replace("@\d+@", "", $partition);
	shell_exec("/usr/sbin/hdparm -z {$disk}");
	sleep(3);
	@touch($GLOBALS['paths']['reload']);
}

function format_disk($dev, $fs) {
	# making sure it doesn't have partitions
	foreach (get_all_disks_info() as $d) {
		if ($d['device'] == $dev && count($d['partitions']) && $d['partitions'][0]['fstype'] != "precleared") {
			unassigned_log("Aborting format: disk '{$dev}' has '".count($d['partitions'])."' partition(s).");
			return FALSE;
		}
	}
	$max_mbr_blocks   = hexdec("0xFFFFFFFF");
	$disk_blocks      = intval(trim(exec("blockdev --getsz $dev  | awk '{ print $1 }'")));
	$disk_schema      = ( $disk_blocks >= $max_mbr_blocks ) ? "gpt" : "msdos";
	unassigned_log("Clearing partition table of disk '$dev'.");
	shell_exec("/usr/bin/dd if=/dev/zero of={$dev} bs=2M count=1");
	unassigned_log("Reloading disk '{$dev}' partition table.");
	shell_exec("/usr/sbin/hdparm -z {$dev}");
	unassigned_log("Creating a '{$disk_schema}' partition table on disk '{$dev}'.");
	shell_exec("/usr/sbin/parted {$dev} --script -- mklabel {$disk_schema}");
	unassigned_log("Creating a primary partition on disk '{$dev}'.");
	shell_exec("/usr/sbin/parted -a optimal {$dev} --script -- mkpart primary 0% 100%");
	unassigned_log("Formatting disk '{$dev}' with '$fs' filesystem.");
	shell_exec(get_format_cmd("{$dev}1", $fs));
	unassigned_log("Reloading disk '{$dev}' partition table.");
	shell_exec("/usr/sbin/hdparm -z {$dev}");
	sleep(3);
	@touch($GLOBALS['paths']['reload']);
}

function remove_partition($dev, $part) {
	foreach (get_all_disks_info() as $d) {
		if ($d['device'] == $dev) {
			foreach ($d['partitions'] as $p) {
				if ($p['part'] == $part && $p['target']) {
					unassigned_log("Aborting removal: partition '{$part}' is mounted.");
					return FALSE;
				} 
			}
		}
	}
	unassigned_log("Removing partition '{$part}' from disk '{$dev}'.");
	shell_exec("/usr/sbin/parted {$dev} --script -- rm {$part}");
}

#########################################################
############        CONFIG FUNCTIONS        #############
#########################################################

function get_config($sn, $var) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	return  (isset($config[$sn][$var])) ? html_entity_decode($config[$sn][$var]) : FALSE;
}

function set_config($sn, $var, $val) {
	$config_file = $GLOBALS["paths"]["config_file"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$sn][$var] = htmlentities($val, ENT_COMPAT);
	save_ini_file($config_file, $config);
	return (isset($config[$sn][$var])) ? $config[$sn][$var] : FALSE;
}

function is_automount($sn) {
	$auto = get_config($sn, "automount");
	return ( ($auto) ? ( ($auto == "yes") ? TRUE : FALSE ) : FALSE);
}

function toggle_automount($sn, $status) {
	$config_file = $GLOBALS["paths"]["config_file"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0777,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$sn]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$sn]["automount"] == "yes") ? TRUE : FALSE;
}

function execute_script($info, $action, $testing = FALSE) { 
	$out = ''; 
	$error = '';
	putenv("ACTION=${action}");
	foreach ($info as $key => $value) {
		putenv(strtoupper($key)."=${value}");
	}
	$cmd = $info['command'];
	$bg = ($info['command_bg'] == "true" && $action == "ADD") ? "&" : "";
	if ($common_cmd = get_config("Config", "common_cmd")) {
		unassigned_log("Running common script: '{$common_cmd}'");
		exec($common_cmd);
	}
	if (! $cmd) {unassigned_log("Command not available.  Cannot execute script."); return FALSE;}
	unassigned_log("Running command '${cmd}' with action '${action}'.");
	if (! is_executable($cmd) ) {
		@chmod($cmd, 0755);
	}
	if (! $testing) {
		$cmd = isset($info['serial']) ? "$cmd > /tmp/${info[serial]}.log 2>&1 $bg" : "$cmd > /tmp/".preg_replace('~[^\w]~i', '', $info['device']).".log 2>&1 $bg";
		exec($cmd);
	} else {
		return $cmd;
	}
}

function set_command($sn, $cmd) {
	$config_file = $GLOBALS["paths"]["config_file"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$sn]["command"] = htmlentities($cmd, ENT_COMPAT);
	save_ini_file($config_file, $config);
	return (isset($config[$sn]["command"])) ? TRUE : FALSE;
}

function remove_config_disk($sn) {
	$config_file = $GLOBALS["paths"]["config_file"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	if ( isset($config[$source]) ) {
		unassigned_log("Removing configuration '$source'.");
	}
	$command = $config[$source][command];
	if ( isset($command) && is_file($command) ) {
		@unlink($command);
		unassigned_log("Removing script '$command'.");
	}
	unset($config[$sn]);
	save_ini_file($config_file, $config);
	return (isset($config[$sn])) ? TRUE : FALSE;
}

#########################################################
############        MOUNT FUNCTIONS        ##############
#########################################################

function is_mounted($dev) {
	return (shell_exec("mount 2>&1|grep -c '${dev} '") == 0) ? FALSE : TRUE;
}

function get_mount_params($fs, $dev) {
	$discard = trim(shell_exec("cat /sys/block/".preg_replace("#\d+#i", "", basename($dev))."/queue/discard_max_bytes 2>/dev/null")) ? ",discard" : "";
	switch ($fs) {
		case 'hfsplus':
			return "force,rw,users,async,umask=000";
			break;
		case 'xfs':
			return "rw,noatime,nodiratime{$discard}";
			break;
		case 'exfat':
		case 'vfat':
		case 'ntfs':
			return "auto,async,nodev,nosuid,umask=000";
			break;
		case 'ext4':
			return "auto,async,nodev,nosuid{$discard}";
			break;
		case 'cifs':
			return "rw,nounix,iocharset=utf8,_netdev,file_mode=0777,dir_mode=0777,username=%s,password=%s";
			break;
		default:
			return "auto,async,noatime,nodiratime";
			break;
	}
}

function do_mount($info) {
	if ($info['fstype'] == "cifs") {
		return do_mount_samba($info);
	} else {
		return do_mount_local($info);
	}
}

function do_mount_local($info) {
	$dev = $info['device'];
	$dir = $info['mountpoint'];
	$fs  = $info['fstype'];
	if (! is_mounted($dev) || ! is_mounted($dir)) {
		if ($fs){
			@mkdir($dir,0777,TRUE);
			$cmd = "mount -t $fs -o ".get_mount_params($fs, $dev)." '${dev}' '${dir}'";
			unassigned_log("Mount drive command: $cmd");
			$o = shell_exec($cmd." 2>&1");
			if ($o != "" && $fs == "ntfs") {
				unassigned_log("Mount failed with error: $o");
				$cmd = "mount -t $fs -r -o ".get_mount_params($fs, $dev)." '${dev}' '${dir}'";
				unassigned_log("Mount drive ro command: $cmd");
				$o = shell_exec($cmd." 2>&1");
				if ($o != "") {
					unassigned_log("Mount ro failed with error: $o");
				}
			}
			foreach (range(0,5) as $t) {
				if (is_mounted($dev)) {
					@chmod($dir, 0777);@chown($dir, 99);@chgrp($dir, 100);
					unassigned_log("Successfully mounted '${dev}' on '${dir}'");
					return TRUE;
				} else {
					sleep(0.5);
				}
			}
			unassigned_log("Mount of '${dev}' failed. Error message: $o");
			rmdir($dir);
			return FALSE;
		} else {
			unassigned_log("No filesystem detected, aborting.");
		}
	} else {
		unassigned_log("Drive '$dev' already mounted...");
	}
}

function do_unmount($dev, $dir, $force = FALSE) {
	if (is_mounted($dev) != 0) {
		unassigned_log("Unmounting '${dev}'...");
		$o = shell_exec("umount".($force ? " -f -l" : "")." '${dev}' 2>&1");
		for ($i=0; $i < 10; $i++) {
			if (! is_mounted($dev)) {
				if (is_dir($dir)) rmdir($dir);
				unassigned_log("Successfully unmounted '$dev'");
				return TRUE;
			} else {
				sleep(0.5);
			}
		}
		unassigned_log("Unmount of '${dev}' failed. Error message: \n$o"); 
		sleep(1);
		if (! lsof($dir) && ! $force) {
			unassigned_log("Since there aren't open files, try to force unmount.");
			return do_unmount($dev, $dir, true);
		}
		return FALSE;
	}
}

#########################################################
############        SHARE FUNCTIONS         #############
#########################################################

function is_shared($name) {
	return ( shell_exec("smbclient -g -L localhost -U% 2>&1|awk -F'|' '/Disk/{print $2}'|grep -c '${name}'") == 0 ) ? FALSE : TRUE;
}

function config_shared($sn, $part) {
	$share = get_config($sn, "share.{$part}");
	return ( ($share) ? ( ($share == "yes") ? TRUE : FALSE ) : FALSE);
}

function toggle_share($serial, $part, $status) {
	$new = ($status == "true") ? "yes" : "no";
	set_config($serial, "share.{$part}", $new);
	return ($new == 'yes') ? TRUE:FALSE;
}

function add_smb_share($dir, $share_name) {
	global $paths, $var, $users;

	if ( ($var['shareSMBEnabled'] == "yes") ) {
		$share_name = basename($dir);
		$config = is_file($paths['config_file']) ? @parse_ini_file($paths['config_file'], true) : array();
		$config = $config["Config"];

		if ($config["smb_security"] == "yes") {
			$read_users = $write_users = $valid_users = array();
			foreach ($users as $key => $user) {
				if ($user['name'] != "root" ) {
					$valid_users[] = $user['name'];
				}
			}

			$invalid_users = array_filter($valid_users, function($v) use($config, &$read_users, &$write_users) { 
				if ($config["smb_{$v}"] == "read-only") {$read_users[] = $v;}
				elseif ($config["smb_{$v}"] == "read-write") {$write_users[] = $v;}
				else {return $v;}
			});
			$valid_users = array_diff($valid_users, $invalid_users);
			if (count($valid_users)) {
				$valid_users = "\nvalid users = ".implode(', ', $valid_users);
				$write_users = count($write_users) ? "\nwrite list = ".implode(', ', $write_users) : "";
				$read_users = count($read_users) ? "\nread users = ".implode(', ', $read_users) : "";
				$share_cont =  "[{$share_name}]\npath = {$dir}{$valid_users}{$write_users}{$read_users}";
			} else {
				$share_cont =  "[{$share_name}]\npath = {$dir}\ninvalid users = @users";
				unassigned_log("Error: No valid smb users defined.  Share '{$dir}' cannot be accessed.");
			}
		} else {
			$share_cont = "[{$share_name}]\npath = {$dir}\nread only = No\nguest ok = Yes ";
		}

		if(!is_dir($paths['smb_usb_shares'])) @mkdir($paths['smb_usb_shares'],0755,TRUE);
		$share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");

		unassigned_log("Defining share '$share_name' on file '$share_conf'");
		file_put_contents($share_conf, $share_cont);
		if (! exist_in_file($paths['smb_extra'], $share_conf)) {
			unassigned_log("Adding share '$share_name' to '".$paths['smb_extra']."'");
			$c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
			$c[] = ""; $c[] = "include = $share_conf";
			# Do Cleanup
			$smb_extra_includes = array_unique(preg_grep("/include/i", $c));
			foreach($smb_extra_includes as $key => $inc) if( ! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
			$c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
			$c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
			file_put_contents($paths['smb_extra'], $c);
		}
		unassigned_log("Reloading Samba configuration...");
		shell_exec("killall -s 1 smbd 2>/dev/null && killall -s 1 nmbd 2>/dev/null");
		shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
		if (is_shared($share_name)) {
			unassigned_log("Directory '${dir}' shared successfully."); return TRUE;
		} else {
			unassigned_log("Sharing directory '${dir}' failed."); return FALSE;
		}
	} else {
		return TRUE;
	}
}

function rm_smb_share($dir, $share_name) {
	global $paths, $var;

	if ( ($var['shareSMBEnabled'] == "yes") ) {
		$share_name = basename($dir);
		$share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
		if (is_file($share_conf)) {
			@unlink($share_conf);
			unassigned_log("Removing SMB share file '$share_conf'");
		}
		if (exist_in_file($paths['smb_extra'], $share_conf)) {
			unassigned_log("Removing SMB share definitions from '".$paths['smb_extra']."'");
			$c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
			# Do Cleanup
			$smb_extra_includes = array_unique(preg_grep("/include/i", $c));
			foreach($smb_extra_includes as $key => $inc) if(! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
			$c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
			$c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
			file_put_contents($paths['smb_extra'], $c);

			unassigned_log("Reloading Samba configuration..");
			shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) close-share '${share_name}' 2>&1");
			shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
			if(! is_shared($share_name)) {
				unassigned_log("Successfully removed SMB share '${share_name}'."); return TRUE;
			} else {
				unassigned_log("Removal of SMB share '${share_name}' failed."); return FALSE;
			}
		} else {
			return TRUE;
		}
	} else {
		return TRUE;
	}
}

function add_nfs_share($dir) {
	global $var;

	if ( ($var['shareNFSEnabled'] == "yes") && (get_config("Config", "nfs_export") == "yes") ) {
		$reload = FALSE;
		foreach (array("/etc/exports","/etc/exports-") as $file) {
			if (! exist_in_file($file, "\"{$dir}\"")) {
				$c = (is_file($file)) ? @file($file,FILE_IGNORE_NEW_LINES) : array();
				unassigned_log("Adding NFS share '$dir' to '$file'.");
				$fsid = 200 + count(preg_grep("@^\"@", $c));
				$c[] = "\"{$dir}\" -async,no_subtree_check,fsid={$fsid} *(sec=sys,rw,insecure,anongid=100,anonuid=99,all_squash)";
				$c[] = "";
				file_put_contents($file, implode(PHP_EOL, $c));
				$reload = TRUE;
			}
		}
		if ($reload) shell_exec("/usr/sbin/exportfs -ra");
	}
	return TRUE;
}

function rm_nfs_share($dir) {
	global $var;

	if ( ($var['shareNFSEnabled'] == "yes") && (get_config("Config", "nfs_export") == "yes") ) {
		$reload = FALSE;
		foreach (array("/etc/exports","/etc/exports-") as $file) {
			if ( exist_in_file($file, "\"{$dir}\"") && strlen($dir)) {
				$c = (is_file($file)) ? @file($file,FILE_IGNORE_NEW_LINES) : array();
				unassigned_log("Removing NFS share '$dir' from '$file'.");
				$c = preg_grep("@\"{$dir}\"@i", $c, PREG_GREP_INVERT);
				file_put_contents($file, implode(PHP_EOL, $c));
				$reload = TRUE;
			}
		}
		if ($reload) shell_exec("/usr/sbin/exportfs -ra");
	}
	return TRUE;
}

function reload_shares() {
	// Disk mounts
	foreach (get_unasigned_disks() as $name => $disk) {
		foreach ($disk['partitions'] as $p) {
			if ( is_mounted(realpath($p)) ) {
				$info = get_partition_info($p);
				if (is_shared(basename($info['target']))) {
					if (config_shared( $info['serial'],  $info['part'])) {
						unassigned_log("Reloading shared dir '{$info[target]}'.");
						unassigned_log("Removing old config...");
						rm_smb_share($info['target'], $info['label']);
						rm_nfs_share($info['target']);
						unassigned_log("Adding new config...");
						add_smb_share($info['mountpoint'], $info['label']);
						add_nfs_share($info['mountpoint']);
					}
				}
			}
		}
	}

	// SMB Mounts
	foreach (get_samba_mounts() as $name => $info) {
		if ( is_mounted($info['device']) ) {
			unassigned_log("Reloading shared dir '{$info[mountpoint]}'.");
			unassigned_log("Removing old config...");
			rm_smb_share($info['mountpoint'], $info['device']);
			add_smb_share($info['mountpoint'], $info['device']);
		}
	}
}

#########################################################
############        SAMBA FUNCTIONS         #############
#########################################################

function get_samba_config($source, $var) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}

function set_samba_config($source, $var, $val) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$source][$var] = $val;
	save_ini_file($config_file, $config);
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}

function is_samba_automount($sn) {
	$auto = get_samba_config($sn, "automount");
	return ( ($auto) ? ( ($auto == "yes") ? TRUE : FALSE ) : TRUE);
	}

function get_samba_mounts() {
	global $paths;

	$o = array();
	$config_file = $GLOBALS["paths"]["samba_mount"];
	$samba_mounts = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	foreach ($samba_mounts as $device => $mount) {
		$mount['device'] = $device;
		$mount['target'] = trim(shell_exec("cat /proc/mounts 2>&1|grep '".str_replace(" ", '\\\040', $device)."'|awk '{print $2}'"));
		$mount['fstype'] = "cifs";
		$mount['size']   = intval(trim(shell_exec("df --output=size,source 2>/dev/null|grep -v 'Filesystem'|grep '${device}'|awk '{print $1}'")))*1024;
		$mount['used']   = intval(trim(shell_exec("df --output=used,source 2>/dev/null|grep -v 'Filesystem'|grep '${device}'|awk '{print $1}'")))*1024;
		$mount['avail']  = $mount['size'] - $mount['used'];
		$mount['automount'] = is_samba_automount($mount['device']);
		if (! $mount["mountpoint"]) {
			$mount["mountpoint"] = $mount['target'] ? $mount['target'] : preg_replace("%\s+%", "_", "{$paths[usb_mountpoint]}/{$mount[ip]}_{$mount[share]}");
		}
		$o[] = $mount;
	}
	return $o;
}

function do_mount_samba($info) {
	$dev = $info['device'];
	$dir = $info['mountpoint'];
	$fs  = $info['fstype'];
	if (! is_mounted($dev) || ! is_mounted($dir)) {
		@mkdir($dir,0777,TRUE);
		$params = sprintf(get_mount_params($fs, $dev), ($info['user'] ? $info['user'] : "guest" ), $info['pass']);
		$cmd = "mount -t $fs -o ".$params." '${dev}' '${dir}'";
		$params = sprintf(get_mount_params($fs, $dev), ($info['user'] ? $info['user'] : "guest" ), '*******');
		unassigned_log("Mount SMB command: mount -t $fs -o ".$params." '${dev}' '${dir}'");
		$o = shell_exec($cmd." 2>&1");
		foreach (range(0,5) as $t) {
			if (is_mounted($dev)) {
				@chmod($dir, 0777);@chown($dir, 99);@chgrp($dir, 100);
				unassigned_log("Successfully mounted '${dev}' on '${dir}'.");
				return TRUE;
			} else {
				sleep(0.5);
			}
		}
		unassigned_log("Mount of '${dev}' failed. Error message: $o");
		return FALSE;
	} else {
		unassigned_log("Share '$dev' already shared...");
	}
}

function toggle_samba_automount($source, $status) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0777,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$config[$source]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$source]["automount"] == "yes") ? TRUE : FALSE;
}

function remove_config_samba($source) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
	$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	if ( isset($config[$source]) ) {
		unassigned_log("Removing configuration '$source'.");
	}
	$command = $config[$source][command];
	if ( isset($command) && is_file($command) ) {
		@unlink($command);
		unassigned_log("Removing script '$command'.");
	}
	unset($config[$source]);
	save_ini_file($config_file, $config);
	return (isset($config[$source])) ? TRUE : FALSE;
}

#########################################################
############         DISK FUNCTIONS         #############
#########################################################

function get_unasigned_disks() {
	global $disks, $var;

	$ud_disks = $paths = $unraid_disks = $unraid_cache = array();

	// Get all devices by id.
	foreach (listDir("/dev/disk/by-id") as $p) {
		$r = realpath($p);
		// Only /dev/sd* and /dev/hd* devices.
		if (!is_bool(strpos($r, "/dev/sd")) || !is_bool(strpos($r, "/dev/hd"))) {
			$paths[$r] = $p;
		}
	}
	natsort($paths);

	// Get the flash device.
	$unraid_disks[] = "/dev/".$disks['flash']['device'];

	// Get the array devices.
	for ($i = 1; $i < $var['SYS_ARRAY_SLOTS']; $i++) {
		if (isset($disks["disk".$i]['device'])) {
			$dev = $disks["disk".$i]['device'];
			$unraid_disks[] = "/dev/".$dev;
		}
	}

	// Get the parity device.
	if ( $disks['parity']['device'] != "") {
		$unraid_disks[] = "/dev/".$disks['parity']['device'];
	}

	// Get all cache devices.
	if ( $disks['cache']['device'] != "") {
		$unraid_disks[] = "/dev/".$disks['cache']['device'];
	}
	for ($i = 2; $i <= $var['SYS_CACHE_SLOTS']; $i++) {
		if (isset($disks["cache".$i]['device'])) {
			$dev = $disks["cache".$i]['device'];
			$unraid_disks[] = "/dev/".$dev;
		}
	}
	foreach ($unraid_disks as $k) {$o .= "  $k\n";}; unassigned_log("UNRAID DISKS:\n$o", "DEBUG");

	// Create the array of unassigned devices.
	foreach ($paths as $path => $d) {
		if (preg_match("#^(.(?!wwn|part))*$#", $d)) {
			if (! in_array($path, $unraid_disks)) {
				if (in_array($path, array_map(function($ar){return $ar['device'];},$ud_disks)) ) continue;
				$m = array_values(preg_grep("#$d.*-part\d+#", $paths));
				natsort($m);
				$ud_disks[$d] = array("device"=>$path,"type"=>"ata","partitions"=>$m);
				unassigned_log("Unassigned disk: '$d'.", "DEBUG");
			} else {
				unassigned_log("Discarded: => '$d' '($path)'.", "DEBUG");
				continue;
			}
		}
	}
	return $ud_disks;
}

function get_all_disks_info($bus="all") {
	unassigned_log("Starting get_all_disks_info.", "DEBUG");
	$d1 = time();
	$ud_disks = get_unasigned_disks();
	foreach ($ud_disks as $key => $disk) {
		if ($disk['type'] != $bus && $bus != "all") continue;
		$disk['temperature'] = "*";
		$disk['size'] = intval(trim(shell_exec("blockdev --getsize64 ${key} 2>/dev/null")));
		$disk = array_merge($disk, get_disk_info($key));
		foreach ($disk['partitions'] as $k => $p) {
			if ($p) $disk['partitions'][$k] = get_partition_info($p);
		}
		$ud_disks[$key] = $disk;
	}
	unassigned_log("Total time: ".(time() - $d1)."s", "DEBUG");
	usort($ud_disks, create_function('$a, $b','$key="device";if ($a[$key] == $b[$key]) return 0; return ($a[$key] < $b[$key]) ? -1 : 1;'));
	return $ud_disks;
}

function get_udev_info($device, $udev=NULL, $reload) {
	global $paths;

	$state = is_file($paths['state']) ? @parse_ini_file($paths['state'], true) : array();
	if ($udev) {
		$state[$device] = $udev;
		save_ini_file($paths['state'], $state);
		return $udev;
	} else if (array_key_exists($device, $state) && ! $reload) {
		unassigned_log("Using udev cache for '$device'.", "DEBUG");
		return $state[$device];
	} else {
		$state[$device] = parse_ini_string(shell_exec("udevadm info --query=property --path $(udevadm info -q path -n $device 2>/dev/null) 2>/dev/null"));
		save_ini_file($paths['state'], $state);
		unassigned_log("Not using udev cache for '$device'.", "DEBUG");
		return $state[$device];
	}
}

function get_disk_info($device, $reload=FALSE){
	$disk = array();
	$attrs = (isset($_ENV['DEVTYPE'])) ? get_udev_info($device, $_ENV, $reload) : get_udev_info($device, NULL, $reload);
	$device = realpath($device);
	$disk['serial_short'] = isset($attrs["ID_SCSI_SERIAL"]) ? $attrs["ID_SCSI_SERIAL"] : $attrs['ID_SERIAL_SHORT'];
	$disk['serial']       = "{$attrs[ID_MODEL]}_{$disk[serial_short]}";
	$disk['device']       = $device;
	return $disk;
}

function get_partition_info($device, $reload=FALSE){
	global $_ENV, $paths;

	$disk = array();
	$attrs = (isset($_ENV['DEVTYPE'])) ? get_udev_info($device, $_ENV, $reload) : get_udev_info($device, NULL, $reload);
	// $GLOBALS["echo"]($attrs);
	$device = realpath($device);
	if ($attrs['DEVTYPE'] == "partition") {
		$disk['serial_short'] = isset($attrs["ID_SCSI_SERIAL"]) ? $attrs["ID_SCSI_SERIAL"] : $attrs['ID_SERIAL_SHORT'];
		$disk['serial']       = "{$attrs[ID_MODEL]}_{$disk[serial_short]}";
		$disk['device']       = $device;
		// Grab partition number
		preg_match_all("#(.*?)(\d+$)#", $device, $matches);
		$disk['part']   =  $matches[2][0];
		$disk['disk']   =  $matches[1][0];
		if (isset($attrs['ID_FS_LABEL'])){
			$disk['label'] = safe_name($attrs['ID_FS_LABEL_ENC']);
		} else {
			if (isset($attrs['ID_VENDOR']) && isset($attrs['ID_MODEL'])){
				$disk['label'] = sprintf("%s %s", safe_name($attrs['ID_VENDOR']), safe_name($attrs['ID_MODEL']));
			} else {
				$disk['label'] = safe_name($attrs['ID_SERIAL']);
			}
			$all_disks = array_unique(array_map(function($ar){return realpath($ar);},listDir("/dev/disk/by-id")));
			$disk['label']  = (count(preg_grep("%".$matches[1][0]."%i", $all_disks)) > 2) ? $disk['label']."-part".$matches[2][0] : $disk['label'];
		}
		$disk['fstype'] = safe_name($attrs['ID_FS_TYPE']);
		$disk['fstype'] = (! $disk['fstype'] && verify_precleared($disk['disk'])) ? "precleared" : $disk['fstype'];
		$disk['target'] = str_replace("\\040", " ", trim(shell_exec("cat /proc/mounts 2>&1|grep ${device}|awk '{print $2}'")));
		$disk['size']   = intval(trim(shell_exec("blockdev --getsize64 ${device} 2>/dev/null")));
		$disk['used']   = intval(trim(shell_exec("df --output=used,source 2>/dev/null|grep -v 'Filesystem'|grep ${device}|awk '{print $1}'")))*1024;
		$disk['avail']  = $disk['size'] - $disk['used'];
		if ( $disk['mountpoint'] = get_config($disk['serial'], "mountpoint.{$disk[part]}") ) {
			if (! $disk['mountpoint'] ) goto empty_mountpoint;
		} else {
			empty_mountpoint:
			$disk['mountpoint'] = $disk['target'] ? $disk['target'] : preg_replace("%\s+%", "_", sprintf("%s/%s", $paths['usb_mountpoint'], $disk['label']));
		}
		$disk['owner'] = (isset($_ENV['DEVTYPE'])) ? "udev" : "user";
		$disk['automount'] = is_automount($disk['serial']);
		$disk['shared'] = ($disk['target']) ? is_shared(basename($disk['mountpoint'])) : config_shared($disk['serial'], $disk['part']);
		$disk['command'] = get_config($disk['serial'], "command.{$disk[part]}");
		$disk['command_bg'] = get_config($disk['serial'], "command_bg.{$disk[part]}");
		$disk['prog_name'] = basename($disk['command'], ".sh");
		$disk['logfile'] = $paths['device_log'].$disk['prog_name'].".log";
		return $disk;
	}
}

function get_fsck_commands($fs, $dev, $type = "ro") {
	switch ($fs) {
		case 'vfat':
			$cmd = array('ro'=>'/sbin/fsck -n %s','rw'=>'/sbin/fsck -a %s');
			break;
		case 'ntfs':
			$cmd = array('ro'=>'/bin/ntfsfix -n %s','rw'=>'/bin/ntfsfix -b -d %s');
			break;
		case 'hfsplus';
			$cmd = array('ro'=>'/usr/sbin/fsck.hfsplus -l %s','rw'=>'/usr/sbin/fsck.hfsplus -y %s');
			break;
		case 'xfs':
			$cmd = array('ro'=>'/sbin/xfs_repair -n %s','rw'=>'/sbin/xfs_repair %s');
			break;
		case 'exfat':
			$cmd = array('ro'=>'/sbin/fsck.exfat %s','rw'=>'/sbin/fsck.exfat %s');
			break;
		case 'btrfs':
			$cmd = array('ro'=>'/sbin/btrfs scrub start -B -R -d -r %s','rw'=>'/sbin/btrfs scrub start -B -R -d %s');
			break;
		case 'ext4':
			$cmd = array('ro'=>'/sbin/fsck.ext4 -vn %s','rw'=>'/sbin/fsck.ext4 -v -f -p %s');
			break;
		case 'reiserfs':
			$cmd = array('ro'=>'/sbin/reiserfsck --check %s','rw'=>'/sbin/reiserfsck --fix-fixable %s');
			break;
		default:
			$cmd = array('ro'=>false,'rw'=>false);
		break;
	}
	return $cmd[$type] ? sprintf($cmd[$type], $dev) : "";
}

function setSleepTime($device) {
	$device = preg_replace("/\d+$/", "", $device);
	shell_exec("hdparm -S180 $device 2>&1");
}
