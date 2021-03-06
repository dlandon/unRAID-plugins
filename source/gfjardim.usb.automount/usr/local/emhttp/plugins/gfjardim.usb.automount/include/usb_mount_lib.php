<?
$plugin = "gfjardim.usb.automount";

$paths = array("smb_extra"       => "/boot/config/smb-extra.conf",
               "smb_usb_shares"  => "/etc/samba/smb-usb-shares",
               "usb_mountpoint"  => "/mnt/usb",
               "log"             => "/var/log/usb_automount.log",
               "config_file"     => "/boot/config/plugins/${plugin}/automount.cfg",
               "state"           => "/var/state/${plugin}.ini"
               );


#########################################################
#############        MISC FUNCTIONS        ##############
#########################################################

$echo = function($m) { echo "<pre>".print_r($m,TRUE)."</pre>";}; 

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

function debug($m){
  $m = "\n".date("D M j G:i:s T Y").": $m";
  file_put_contents($GLOBALS["paths"]["log"], $m, FILE_APPEND);
  // echo print_r($m,true)."\n";
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

function get_temp($dev) {
  if (is_disk_running($dev)) {
    $temp = trim(shell_exec("smartctl -A -d sat,12 $dev 2>/dev/null| grep -m 1 -i Temperature_Celsius | awk '{print $10}'"));
    return (is_numeric($temp)) ? $temp : "*";
  }
}

#########################################################
############        CONFIG FUNCTIONS        #############
#########################################################

function get_config($sn, $var) {
  $config_file = $GLOBALS["paths"]["config_file"];
  $config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
  return (isset($config[$sn][$var])) ? html_entity_decode($config[$sn][$var]) : FALSE;
}

function set_config($sn, $var, $val) {
  $config_file = $GLOBALS["paths"]["config_file"];
  if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
  $config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
  $config[$sn][$var] = htmlentities($val, ENT_COMPAT);
  save_ini_file($config_file, $config);
  return (isset($config[$sn][$var])) ? $config[$sn][$var] : FALSE;
}

function is_automount($sn, $usb=true) {
  $auto = get_config($sn, "automount");
  return ( ($auto) ? ( ($auto == "yes") ? TRUE : FALSE ) : TRUE);
}

function is_automount_2($sn, $usb=FALSE) {
  $auto = get_config($sn, "automount");
  return ($auto == "yes" || ( ! $auto && $usb !== FALSE ) ) ? TRUE : FALSE; 
}

function toggle_automount($sn, $status) {
  $config_file = $GLOBALS["paths"]["config_file"];
  if (! is_file($config_file)) @mkdir(dirname($config_file),0777,TRUE);
  $config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
  $config[$sn]["automount"] = ($status == "true") ? "yes" : "no";
  save_ini_file($config_file, $config);
  return ($config[$sn]["automount"] == "yes") ? TRUE : FALSE;
}

function execute_script($info, $action) { 
  $out = ''; 
  $error = '';
  putenv("ACTION=${action}");
  foreach ($info as $key => $value) putenv(strtoupper($key)."=${value}");
  $cmd = get_config($info['serial'], "command.{$info[part]}");
  if (! $cmd) {debug("Command not available, skipping."); return FALSE;}
  debug("Running command '${cmd}' with action '${action}'.");
  @chmod($cmd, 0777);
  exec("$cmd > /tmp/${info[serial]}.log 2>&1");
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

function get_mount_params($fs) {
  switch ($fs) {
    case 'hfsplus':
      return "force,rw,users,async,umask=000";
      break;
    case 'xfs':
      return 'rw,noatime,nodiratime,attr2,inode64,noquota';
      break;
    default:
      return "auto,async,nodev,nosuid,umask=000";
      break;
  }
}

function do_mount($dev, $dir, $fs) {
  if (! is_mounted($dev) || ! is_mounted($dir)) {
    @mkdir($dir,0777,TRUE);
    $cmd = "mount -t auto -o ".get_mount_params($fs)." '${dev}' '${dir}'";
    debug("Mounting drive with command: $cmd");
    $o = shell_exec($cmd." 2>&1");
    foreach (range(0,5) as $t) {
      if (is_mounted($dev)) {
        debug("Successfully mounted '${dev}' on '${dir}'"); return TRUE;
      } else { sleep(0.5);}
    }
    debug("Mount of ${dev} failed. Error message: $o"); return FALSE;
  } else {
    debug("Drive '$dev' already mounted");
  }
}

function do_unmount($dev, $dir) {
  if (is_mounted($dev) != 0){
    debug("Unmounting ${dev}...");
    $o = shell_exec("umount '${dev}' 2>&1");
    for ($i=0; $i < 10; $i++) {
      if (! is_mounted($dev)){
        if (is_dir($dir)) rmdir($dir);
        debug("Successfully unmounted '$dev'"); return TRUE;
      } else { sleep(0.5);}
    }
    debug("Unmount of ${dev} failed. Error message: $o"); return FALSE;
  }
}

#########################################################
############        SHARE FUNCTIONS         #############
#########################################################

function is_shared($name) {
  return ( shell_exec("smbclient -g -L localhost -U% 2>&1|awk -F'|' '/Disk/{print $2}'|grep -c '${name}'") == 0 ) ? FALSE : TRUE;
}

function add_smb_share($dir, $share_name) {
  global $paths;
  if(!is_dir($paths['smb_usb_shares'])) @mkdir($paths['smb_usb_shares'],0755,TRUE);
  $share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
  $share_cont = sprintf("[%s]\npath = %s\nread only = No\nguest ok = Yes ", $share_name, $dir);
  debug("Defining share '$share_name' on file '$share_conf' .");
  file_put_contents($share_conf, $share_cont);
  if (! exist_in_file($paths['smb_extra'], $share_name)) {
    debug("Adding share $share_name to ".$paths['smb_extra']);
    $c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
    $c[] = ""; $c[] = "include = $share_conf";
    # Do Cleanup
    $smb_extra_includes = array_unique(preg_grep("/include/i", $c));
    foreach($smb_extra_includes as $key => $inc) if( ! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
    $c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
    $c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
    file_put_contents($paths['smb_extra'], $c);
  }
  debug("Reloading Samba configuration. ");
  shell_exec("killall -s 1 smbd 2>/dev/null && killall -s 1 nmbd 2>/dev/null");
  shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
  if(is_shared($share_name)) {
    debug("Directory '${dir}' shared successfully."); return TRUE;
  } else {
    debug("Sharing directory '${dir}' failed."); return FALSE;
  }
}

function rm_smb_share($dir, $share_name) {
  global $paths;
  $share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
  debug("Removing share definitions from '$share_conf'.");
  if (is_file($share_conf)) {
    @unlink($share_conf);
    debug("Removing share definitions from '$share_conf'.");
  }
  if (exist_in_file($paths['smb_extra'], $share_conf)) {
    debug("Removing share definitions from ".$paths['smb_extra']);
    $c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
    # Do Cleanup
    $smb_extra_includes = array_unique(preg_grep("/include/i", $c));
    foreach($smb_extra_includes as $key => $inc) if(! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
    $c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
    $c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
    file_put_contents($paths['smb_extra'], $c);
  }
  debug("Reloading Samba configuration. ");
  shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) close-share '${share_name}' 2>&1");
  shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
  if(! is_shared($share_name)) {
    debug("Successfully removed share '${share_name}'."); return TRUE;
  } else {
    debug("Removal of share '${share_name}' failed."); return FALSE;
  }
}


#########################################################
############         DISK FUNCTIONS         #############
#########################################################


function get_unasigned_disks() {
  $disks = array();
  $paths=listDir("/dev/disk/by-id");
  natsort($paths);
  $usb_disks = array();
  foreach (listDir("/dev/disk/by-path") as $v) if (preg_match("#usb#", $v)) $usb_disks[] = realpath($v);
  $unraid_flash = realpath("/dev/disk/by-label/UNRAID");
  $unraid_disks = array();
  foreach (parse_ini_string(shell_exec('/root/mdcmd status 2>/dev/null')) as $k => $v) {
    if (strpos($k, "rdevName") !== FALSE) {
      if (strlen($v)) $unraid_disks[] = realpath("/dev/$v");
    }
  }
  $unraid_cache = array();
  foreach (parse_ini_file("/boot/config/disk.cfg") as $k => $v) {
    if (strpos($k, "cacheId") !== FALSE) {
      foreach ( preg_grep("#".$v."#i", $paths) as $c) $unraid_cache[] = realpath($c);
    }
  }
  foreach ($paths as $d) {
    $path = realpath($d);
    if (preg_match("/ata|usb(?:(?!part).)*$/i", $d) && ! in_array($path, $unraid_disks)){
      if ($m = array_values(preg_grep("#$d.*-part\d+#", $paths))) {
        natsort($m);
        foreach ($m as $k => $v) $m_real[$k] = realpath($v);
        if (strpos($d, "ata") !== FALSE && ! count(array_intersect($unraid_cache, $m_real)) && ! in_array($path, $usb_disks)) {
          $disks[$d] = array('device'=>$path,'type'=>'ata','partitions'=>$m);
        } else if ( in_array($path, $usb_disks) && ! in_array($unraid_flash, $m_real)) {
          $disks[$d] = array('device'=>$path,'type'=>'usb','partitions'=>$m);
        }
      }
    }
  }
  return $disks;
}

function get_all_disks_info($bus="all") {
  // $d1 = time();
  $disks = get_unasigned_disks();
  foreach ($disks as $key => $disk) {
    if ($disk['type'] != $bus && $bus != "all") continue;
    $disk['temperature'] = get_temp($key);
    foreach ($disk['partitions'] as $k => $p) {
      $disk['partitions'][$k] = get_partition_info($p);
    }
    $disks[$key] = $disk;
  }
  // debug("get_all_disks_info: ".(time() - $d1));
  return $disks;
}

function get_udev_info($device, $udev=NULL) {
  global $paths;
  $state = is_file($paths['state']) ? @parse_ini_file($paths['state'], true) : array();
  if ($udev) {
    $state[$device] = $udev;
    save_ini_file($paths['state'], $state);
    return $udev;
  } else if (array_key_exists($device, $state)) {
    // debug("Using udev cache for '$device'.");
    return $state[$device];
  } else {
    $state[$device] = parse_ini_string(shell_exec("udevadm info --query=property --path $(udevadm info -q path -n $device ) 2>/dev/null"));
    save_ini_file($paths['state'], $state);
    // debug("Not using udev cache for '$device'.");
    return $state[$device];
  }
}

function get_partition_info($device){
  global $_ENV, $paths;
  $disk = array();
  $attrs = (isset($_ENV['DEVTYPE'])) ? get_udev_info($device, $_ENV) : get_udev_info($device);
  $device = realpath($device);
  if ($attrs['DEVTYPE'] == "partition") {
    $disk['serial']       = $attrs['ID_SERIAL'];
    $disk['serial_short'] = $attrs['ID_SERIAL_SHORT'];
    $disk['device']       = $device;
    // Grab partition number
    preg_match_all("#(.*?)(\d+$)#", $device, $matches);
    $disk['part']   =  $matches[2][0];
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
    $disk['automount'] = is_automount_2($disk['serial'],strpos($attrs['DEVPATH'],"usb"));
    return $disk;
  }
}

function get_fsck_commands($fs) {
  switch ($fs) {
    case 'vfat':
    return array('ro'=>'/sbin/fsck -n %s','rw'=>'/sbin/fsck -a %s');
    break;
    case 'ntfs':
    return array('ro'=>'/bin/ntfsfix %s','rw'=>'/bin/ntfsfix -a %s');
    break;
    case 'exfat':
    return array('ro'=>'/sbin/exfatfsck %s','rw'=>false);
    break;
    case 'hfsplus';
    return array('ro'=>'/usr/sbin/fsck.hfsplus -l %s','rw'=>'/usr/sbin/fsck.hfsplus -y %s');
    break;
    case 'xfs':
    return array('ro'=>'/sbin/xfs_repair -n %s','rw'=>'/sbin/xfs_repair %s');
    break;
  }
}

function setSleepTime($device) {
  $device = preg_replace("/\d+$/", "", $device);
  shell_exec("hdparm -S180 $device 2>&1");
}
