<?php
// vim: set ai ts=4 sw=4 ft=php:
/**
 * This is the FreePBX Big Module Object.
 *
 * PKCS Class for FreePBX's BMO.
 *
 * This is an interface to OpenSSL, for generating certificates
 * the majority of the work was ported from the Asterisk
 * Certificate generation script in contrib/scripts.
 * See: https://wiki.asterisk.org/wiki/display/AST/Secure+Calling+Tutorial
 * Special thanks to Joshua Colp, Matt Jordan and Malcolm Davenport
 *
 * License for all code of this FreePBX module can be found in the license file inside the module directory
 * Copyright 2006-2014 Schmooze Com Inc.
 */
namespace FreePBX;
class PKCS {

	// Our path to openssl.
	private $openssl = "/usr/bin/openssl";

	private $defaults = array(
		"org" => "Asterisk",
		"ca_cn" => "Asterisk Private CA",
		"client_cn" => "asterisk",
		"server_cn" => ""
	);

	// Key location, overrideable by setKeyLocation()
	public $keylocation = false;

	// This is how long we should wait for OpenSSL to run a command.
	// This may need to be tuned on things like the pi.
	public $timeout = 120;

	//TODO first element that comes in here is the freepbx object yikes
	public function __construct($debug=0) {
		$this->defaults['server_cn'] = $this->getHostname();
		if(is_int($debug)) {
			$this->debug = $debug;
		} else {
			$this->debug = 0;
		}
		if(function_exists('fpbx_which')) {
			$command = fpbx_which('openssl');
			$this->openssl = !empty($command) ? $command : $this->openssl;
		}
	}

	// Ensure that permissions are correct in teardown
	public function __destruct() {
		$this->checkPermissions();
	}

	/**
	 * Create a global configuration file for use
	 * when generating more base certificates
	 * @param {string} $cn The Common Name, usually a FQDN
	 * @param {string} $o  The organization name
	 */
	public function createConfig($base = false, $cn = false, $o = false, $force=false) {
		if (!$base) {
			throw new \Exception("No name for this CA");
		}

		// If we weren't given anything, use the defaults.
		if (!$cn) {
			$cn = $this->defaults['ca_cn'];
		}
		if (!$o) {
			$o = $this->defaults['org'];
		}

		$cfglocation = $this->getKeysLocation()."/$base.cfg";

		if(!file_exists($cfglocation) || $force == true) {
			$ca = <<<EOF
[req]
distinguished_name = req_distinguished_name
prompt = no
default_md = sha256

[ca]
default_md = sha256

[req_distinguished_name]
CN={$cn}
O={$o}

[ext]
basicConstraints=CA:TRUE

EOF;
			if(!file_put_contents($cfglocation,$ca)) {
				throw new \Exception(sprintf(_("Unable to create %s config file"),$cfglocation));
			}
			return true;
		}
		// Already exists, not forced.
		return false;
	}

	/**
	 * Create a Certificate Authority. If the CA already exists don't recreate it
	 * or we will end up invalidating all certificates we've already generated
	 * (at some point it would/will happen). Alternatively you can pass the force
	 * option and it will overwrite
	 * @param {string} $base The Certificate authority basename
	 * @param {string} $passphrase  The passphrase used to encrypt the key file
	 * @param {bool} $force=false Whether to force recreation if already exists
	 */
	public function createCA($base = false, $passphrase = false, $force = false) {
		if (!$base) {
			throw new \Exception(_("No name for this CA"));
		}

		$location = $this->getKeysLocation();
		$key = "$location/$base.key";
		if(file_exists($key) && !$force) {
			$this->out(_("CA key already exists, reusing"));
		} else {
			$this->out(_("Creating CA key"));
			@unlink($key);
			$this->generateKey($base, $passphrase, 4096);
		}

		// We have a key.
		// This is generated by $this->createConfig()
		$config = "$location/$base.cfg";

		// This is our ca certificate!
		$cacrt = "$location/$base.crt";

		if(file_exists($cacrt) && !$force) {
			$this->out(_("CA certificate already exists, reusing"));
		} else {
			//Creating CA certificate ${CACERT}
			$this->out(_("Creating CA certificate"));
			if($passphrase) {
				if (strlen($passphrase) < 8) {
					throw new \Exception(_("Invalid password supplied - less than 8 characters"));
				}
				$out = $this->runOpenSSL("req -new -config $config -x509 -days 3650 -key $key -out $cacrt -passin stdin", $passphrase);
			} else {
				$out = $this->runOpenSSL("req -nodes -new -config $config -x509 -days 3650 -key $key -out $cacrt");
			}
			if($out['exitcode'] > 0) {
				throw new \Exception(sprintf(_("Error Generating Certificate: %s"),$out['stderr']));
			}
		}
		return true;
	}

	/**
	 * Create a Certificate from the provided basename
	 * @param {string} $base       The basename
	 * @param {string} $cabase     The Certificate Authority Base name to reference
	 * @param {string} $passphrase The CA key passphrase
	 */
	public function createCert($base,$cabase,$passphrase) {
		$location = $this->getKeysLocation();
		//Creating certificate ${base}.key
		$this->out(sprintf(_("Creating certificate for %s"),$base));
		$out = $this->runOpenSSL("genrsa -out " . $location . "/" . $base . ".key 1024");
		if($out['exitcode'] > 0) {
			throw new \Exception(sprintf(_("Error Generating Key: %s"),$out['stderr']));
		}
		//Creating signing request ${base}.csr
		$this->out(sprintf(_("Creating signing request for %s"),$base));
		$out = $this->runOpenSSL("req -batch -new -config " . $location . "/".$cabase.".cfg -key " . $location . "/" . $base . ".key -out " . $location . "/" . $base . ".csr");
		if($out['exitcode'] > 0) {
			throw new \Exception(sprintf(_("Error Generating Signing Request: %s"),$out['stderr']));
		}
		//Creating certificate ${base}.crt
		$this->out("Creating certificate " . $base);
		if($passphrase) {
			if (strlen($passphrase) < 8) {
				throw new \Exception(_("Invalid password supplied - less than 8 chars"));
			}
			// Generate a key
			$out = $this->runOpenSSL("x509 -req -days 3650 -in " . $location . "/" . $base . ".csr -CA " . $location . "/".$cabase.".crt -CAkey " . $location . "/".$cabase.".key -set_serial 01 -out " . $location . "/" . $base . ".crt -passin stdin", $passphrase);
		} else {
			$out = $this->runOpenSSL("x509 -req -days 3650 -in " . $location . "/" . $base . ".csr -CA " . $location . "/".$cabase.".crt -CAkey " . $location . "/".$cabase.".key -set_serial 01 -out " . $location . "/" . $base . ".crt");
		}
		if($out['exitcode'] > 0) {
			throw new \Exception(sprintf(_("Error Generating Certificate: %s"),$out['stderr']));
		}
		//Combining key and crt into ${base}.pem
		$this->out(sprintf(_("Combining key and crt into %s.pem"),$base));
		$contents = file_get_contents($location . "/" . $base . ".key");
		$contents = $contents . file_get_contents($location . "/" . $base . ".crt");
		file_put_contents($location . "/" . $base . ".pem", $contents);
		return true;
	}


	/**
	 * Create Certificate Signing Request
	 * @param  string $name   The basename of the CSR (File System)
	 * @param  array $params Variables for the CSR. Must have at least 'OU' and 'CN'
	 * @param  bool $regen  Whether to regenerate the CSR if it already exists
	 * @return bool
	 */
	public function createCSR($name = false, $params, $regen = false) {

		$name = $this->validateName($name);

		if (!$name) {
			throw new \Exception(_("Must have a name for the CSR"));
		}

		// Make sure the key has been generated.
		$this->generateKey($name);

		$keyloc = $this->getKeysLocation();
		$csr = "$keyloc/$name.csr";

		if (file_exists($csr)) {
			// Already exists.
			if ($regen) {
				unlink($csr);
			} else {
				return true;
			}
		}

		if (!is_array($params)) {
			throw new \Exception(_("Not an array"));
		}

		if (empty($params['O']) || empty($params['CN'])) {
			throw new \Exception(_("Missing O or CN. Can't create"));
		}

		$defaults = array("C" => "AU", "ST" => "QLD", "L" => "Brisbane", "OU" => "FreePBX Created Certificate",
			"emailAddress" => "invalid@example.com");

		// Load defaults if they're not provided.
		foreach ($defaults as $k => $v) {
			if (empty($params[$k])) {
				$params[$k] = $v;
			}
		}

		// Generate CSR Config
		$config = "[req]
default_bits = 4096
default_keyfile = /error/file/invalid
distinguished_name = req_distinguished_name
prompt = no
default_md = sha256

[req_distinguished_name]
";
		foreach ($params as $k => $v) {
			$config .= "$k = $v\n";
		}

		$keyfile = "$keyloc/$name.key";
		$csrconfig = "$csr-config";
		file_put_contents($csrconfig, $config);
		$out = $this->runOpenSSL("req -batch -new -key $keyfile -out $csr -config $csrconfig");
		if($out['exitcode'] != 0) {
			throw new \Exception(sprintf(_("Can't create CSR, no idea why. $s"),json_encode($out)));
		}
		return true;
	}


	/**
	 * Create a secure key.
	 *
	 * Will not overwrite an existing key.
	 *
	 * @param string Name of the key
	 * @param string Password (null/blank/false gives no key)
	 * @param int Size of key (defaults to 2048)
	 * @return bool true/false if the key was created.
	 */

	public function generateKey($name, $password = false, $bits = 2048) {

		$name = $this->validateName($name);

		if (empty($name)) {
			throw new \Exception(_("Can't generate unnamed key"));
		}
		$keyloc = $this->getKeysLocation();
		$keyfile = "$keyloc/$name.key";

		// Never clobber an existing key.
		if (file_exists($keyfile)) {
			return false;
		}

		if ($password) {
			// Woo! There's a password. Is it valid though?
			if (strlen($password) < 8) {
				throw new \Exception(_("Invalid password supplied - less than 8 chars"));
			}
			// Generate a key
			$out = $this->runOpenSSL("genrsa -des3 -out $keyfile -passout stdin $bits", $password);
			if($out['exitcode'] != 0) {
				throw new \Exception(sprintf(_("Can't create key, no idea why. %s"),json_encode($out)));
			} else {
				return true;
			}
		} else {
			// No password. Easy.
			$out = $this->runOpenSSL("genrsa -out $keyfile $bits");
			if($out['exitcode'] != 0) {
				throw new \Exception(sprintf(_("Can't create key, no idea why. %s"),json_encode($out)));
			} else {
				return true;
			}
		}
	}


	/**
	 * Sign the key that's been generated with our own CA
	 *
	 * @param string Name of the key to sign
	 * @param string Name of the CA to use to sign the key
	 * @param string Password (if any) of the CA
	 * @param int Serial number (default = 0001)
	 */
	public function selfSignCert($name, $caname = "ca", $password = false, $serial = "0001") {
		$life = 3560; // Live for 10 years

		$name = $this->validateName($name);

		if (!$name) {
			throw new \Exception(_("Can't sign unnamed key"));
		}
		$keyloc = $this->getKeysLocation();
		$csrfile = "$keyloc/$name.csr";
		if (!file_exists($csrfile)) {
			throw new \Exception(sprintf(_("No Cert Signing Request for %s"),$name));
		}

		// Make sure the CA we've been asked to use exists.
		$cacrt = "$keyloc/$caname.crt";
		$cakey = "$keyloc/$caname.key";
		if (!file_exists($cacrt) || !file_exists($cakey)) {
			throw new \Exception(sprintf(_("CA %s Doesn't exist"),$caname));
		}

		$certfile = "$keyloc/$name.crt";
		// Woo! Actually sign it!
		if($password) {
			// Don't check the length. Someone may have an old CA they're using that works
			// with less than 8. But you shouldn't. Really, that's like a day's worth of
			// time to crack on a modern CPU.
			$cmd = "x509 -req -sha256 -days $life -in $csrfile -CA $cacrt -CAkey $cakey -set_serial $serial -out $certfile -passin stdin";
			$out = $this->runOpenSSL($cmd, $password);
		} else {
			$cmd = "x509 -req -sha256 -days $life -in $csrfile -CA $cacrt -CAkey $cakey -set_serial $serial -out $certfile";
			$out = $this->runOpenSSL($cmd);
		}
		if($out['exitcode'] != 0) {
			throw new \Exception(sprintf(_("Error Signing Cert with '%s': %s"),$cmd, json_encode($out)));
		}
		return true;
	}

	/**
	 * Actually run OpenSSL
	 * @param string Params to pass to OpenSSL
	 * @param string String to pass into OpenSSL (used to pass passphrases around)
	 * @return array returns assoc array consisting of (array)status, (string)stdout, (string)stderr and (int)exitcode
	 */
	public function runOpenSSL($params, $stdin = null) {

		$fds = array(
			array("pipe", "r"), // stdin
			array("pipe", "w"), // stdout
			array("pipe", "w"), // stderr
			array("pipe", "w"), // Status
		);

		$webuser = \FreePBX::Freepbx_conf()->get('AMPASTERISKWEBUSER');
		$keyloc = $this->getKeysLocation();

		// We need to ensure that our environment variables are sane.
		// Luckily, we know just the right things to say...
		if (!isset($this->opensslenv)) {
			$this->opensslenv['PATH'] = "/bin:/usr/bin:/usr/local/bin";
			$this->opensslenv['USER'] = $webuser;
			$this->opensslenv['HOME'] = $keyloc;
			if (file_exists('/bin/bash')) {
				$this->opensslenv['SHELL'] = "/bin/bash";
			} elseif (file_exists('/usr/local/bin/bash')) {
				$this->opensslenv['SHELL'] = "/usr/local/bin/bash";
			} else {
				$this->opensslenv['SHELL'] = "/bin/sh";
			}
		}

		$cmd = $this->openssl. " $params";
		$proc = proc_open($cmd, $fds, $pipes, "/tmp", $this->opensslenv);

		if (!is_resource($proc)) { // Unable to start!
			throw new \Exception(_("Unable to start OpenSSL"));
		}

		// If we need to send stuff to stdin, then do it!
		if ($stdin) {
			fwrite($pipes[0], $stdin);
			fclose($pipes[0]);
		}

		// Wait $timeout seconds for it to finish.
		$tmp = null;
		$r = array($pipes[3]);
		$otimeout = ini_get('max_execution_time');
		set_time_limit($this->timeout); //more pis. <3 all the pis for taking so long
		if (!stream_select($r , $tmp, $tmp, $this->timeout)) {
			throw new \RuntimeException(sprintf(_("OpenSSL took too long to run the command '%s'"),$cmd));
		}

		$status = explode("\n", stream_get_contents($pipes[3]));
		array_pop($status);  // Remove trailing blank line
		$retarr['status'] = $status;
		$retarr['stdout'] = stream_get_contents($pipes[1]);
		$retarr['stderr'] = stream_get_contents($pipes[2]);
		$exitcode = proc_close($proc);
		$retarr['exitcode'] = $exitcode;
		set_time_limit($otimeout); //reset
		return $retarr;
	}

	/**
	 * Return a list of all Certificates from the key folder
	 * @return array
	 */
	public function getAllCertificates() {
		$keyloc = $this->getKeysLocation();
		return $this->getFileList($keyloc);
	}

	/**
	 * Set the location of the keys.
	 *
	 * This is normally auto-detected. You don't need to use this.
	 * In fact, you don't want to use this unless you're extremely
	 * sure you know what you're doing. No sanity checks are done.
	 *
	 * @param string The location of the key folder
	 */
	public function setKeysLocation($loc) {
		$this->keylocation = $loc;
	}

	/**
	 * Get the Asterisk Key Folder Location
	 * @return string The location of the key folder
	 */
	public function getKeysLocation() {

		// Do we know where it is already?
		if ($this->keylocation) {
			return $this->keylocation;
		}

		$webuser = \FreePBX::Freepbx_conf()->get('AMPASTERISKWEBUSER');

		if (!$webuser) {
			throw new \Exception(_("I don't know who I should be running OpenSSL as."));
		}

		// We need to ensure that we can actually read the Key files.
		$keyloc = \FreePBX::Freepbx_conf()->get('CERTKEYLOC');
		$keyloc = !empty($keyloc) ? $keyloc : FreePBX::Freepbx_conf()->get('ASTETCDIR') . "/keys";
		if (!file_exists($keyloc)) {
			if(!mkdir($keyloc)) {
				throw new \Exception(sprintf(_("Could Not Create the Asterisk Keys Folder: %s"),$keyloc));
			}
		}

		if (is_writable($keyloc)) {
			// This is a good Directory, and we're happy.
			$this->keylocation = $keyloc;
			return $keyloc;
		} else {
			throw new \Exception(sprintf(_("Don't have permission/can't write to: %s"),$keyloc));
		}
	}

	private function validateName($name) {
		// Remove any nasty characters
		$name = str_replace( array('/', "'", '"', '\\', '&', ';', " "), "", $name);
		return $name;
	}

	private function out($message,$level=1) {
		if($level < $this->debug) {
			echo $message . "\n";
		} elseif(function_exists("dbug")) {
			dbug($message);
		}
	}

	/**
	 * Get list of files in a directory
	 * @param string $dir The directory to get the file list of/from
	 */
	public function getFileList($dir) {
		// When we require PHP5.4, use RecursiveDirectoryIterator.
		// Until then..

		$retarr = array();
		$this->recurseDirectory($dir, $retarr, strlen($dir)+1);
		return $retarr;
	}

	/**
	 * Recursive routine for getFileList
	 * @param string $dir The directory to recurse into
	 * @param array $retarry The returned array
	 * @param string $strip What to strip off of the directory
	 */
	private function recurseDirectory($dir, &$retarr, $strip) {

		$dirarr = scandir($dir);
		foreach ($dirarr as $d) {
			// Always exclude hidden files.
			if ($d[0] == ".") {
				continue;
			}
			$fullpath = "$dir/$d";

			if (is_dir($fullpath)) {
				$this->recurseDirectory($fullpath, $retarr, $strip);
			} else {
				$retarr[] = substr($fullpath, $strip);
			}
		}
	}

	/**
	 * Check Permissions on said directory and fix if need be
	 * @param {string} $dir = false The Directory to check and fix
	 */
	private function checkPermissions($dir = false) {
		if (!$dir) {
			// No directory specified. Let's use the default.
			$dir = $this->getKeysLocation();
		}

		// If it ends in a slash, remove it, for sanity
		$dir = rtrim($dir, "/");

		if (!is_dir($dir)) {
			// That's worrying. Can I make it?
			$ret = @mkdir($dir);
			if (!$ret) {
				throw new \Exception(sprintf(_("Directory %s doesn't exist, and I can't make it."),$dir));
			}
		}

		// Now, who should be running OpenSSL normally?
		$freepbxuser = \FreePBX::Freepbx_conf()->get('AMPASTERISKWEBUSER');
		$pwent = posix_getpwnam($freepbxuser);
		$uid = $pwent['uid'];
		$gid = $pwent['gid'];

		// What are the permissions of the keys directory?
		$stat = stat($dir);
		if ($uid != $stat['uid'] || $gid != $stat['gid']) {
			// Permissions are wrong on the keys directory. Hopefully, I'm root, so I can fix them.
			if (posix_geteuid() !== 0) {
				throw new \Exception(sprintf(_("Permissions error on directory %s - please re-run as root to automatically repair"),$dir));
			}
			// We're root. Yay.
			chown($dir, $uid);
			chgrp($dir, $gid);
		}

		// Check the permissions of the files inside the key location
		$allfiles = glob($dir."/*");
		// Add the entropy file
		$allfiles[] = "$dir/.rnd";
		foreach ($allfiles as $file) {
			if (!file_exists($file)) {
				// .rnd file may not exist
				continue;
			}
			$stat = stat($file);
			if ($uid != $stat['uid'] || $gid != $stat['gid']) {
				// Permissions are wrong on the keys directory. Hopefully, I'm root, so I can fix them.
				if (posix_geteuid() !== 0) {
					throw new \Exception(sprintf(_("Permissions error on file %s - please re-run as root to automatically repair"),$file));
				}
				// We're root. Yay.
				chown($file, $uid);
				chgrp($file, $gid);
			}
		}
	}

	/**
	 * Figure out the 'correct' hostname for this machine.
	 *
	 * Sometimes, 'hostname -f', which doesn't do any DNS lookups (which
	 * is what we want) errors.
	 *
	 * @return string Best guess at hostname
	 */
	public function getHostname() {
		// First, try 'hostname -f'
		exec("hostname -f 2>/dev/null", $output, $ret);
		if ($ret === 0 && !empty($output[0])) {
			// Hostname is valid
			return trim($output[0]);
		}
		// It errored for some reason.
		// Just return whatever 'hostname' thinks it is, without FQDN.
		exec("hostname", $raw, $ret);
		if ($ret !== 0 || empty($raw[0])) {
			throw new \Exception("Can not determine hostname. Critical error");
		}
		return trim($raw[0]);
	}

	//Old functions for backwards compatibility with old certman
	public function getAllAuthorityFiles() {
		return array();
	}

	public function removeCert($base) {
		return false;
	}

	public function removeCA() {
		return false;
	}

	public function removeConfig() {
		return false;
	}
}
