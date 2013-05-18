<?php

class Phpr_Installer_Crypt
{
	private static $instance;

	private $mode_descriptor = null;
	private $config_content = null;
	private $salt;
	
	protected function __construct() {}
	
	public static function create()
	{
		if (!self::$instance)
			self::$instance = new self();

		return self::$instance;
	}
	
	public function __destruct()
	{
		if ($this->mode_descriptor)
			mcrypt_module_close($this->mode_descriptor);
	}
	
	public function encrypt_to_file($path, $data, $key)
	{
		if (!is_writable($path))
			@mkdir(dirname($path));

		$data = $this->encrypt($data, $key);
		if (!@file_put_contents($path, $data))
			throw new Exception('Error creating data file. Please make sure that the installation directory and all its subdirectories are writable for PHP.');
	}
	
	public function decrypt_from_file($path, $key)
	{
		if (!file_exists($path))
			throw new Exception('File not found: '.$path);
			
		return $this->decrypt(file_get_contents($path), $key);
	}
	
	public function encrypt($data, $key = null, $salt = null)
	{
		$data = serialize($data);
		
		$descriptor = $this->get_mode_descriptor();
		$key_size = mcrypt_enc_get_key_size($descriptor);
		
	    $strong_key = substr(md5($salt.$key), 0, $key_size);

		srand();
		$iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($descriptor), MCRYPT_RAND);

		mcrypt_generic_init($descriptor, $strong_key, $iv);
		$encrypted  = mcrypt_generic($descriptor, $data);
		mcrypt_generic_deinit($descriptor);

		$iv_enc = $iv.$encrypted;
		return self::obfuscate_data($iv_enc, $strong_key);
	}
	
	public function decrypt($data, $key = null, $salt = null)
	{
		$descriptor = $this->get_mode_descriptor();

		$key_size = mcrypt_enc_get_key_size($descriptor);
		$strong_key = substr(md5($salt.$key), 0, $key_size);

		$data = self::deobfuscate_data($data, $strong_key);

		$iv_size = mcrypt_enc_get_iv_size($descriptor);
		$iv = substr($data, 0, $iv_size);
		$data = substr($data, $iv_size);

		mcrypt_generic_init($descriptor, $strong_key, $iv);
		$result = mdecrypt_generic($descriptor, $data);
		mcrypt_generic_deinit($descriptor);
		
		return unserialize($result);
	}
	
	protected function obfuscate_data(&$data, &$key)
	{
		$strong_key = md5($key);

		$key_size = strlen($strong_key);
		$data_size = strlen($data);
		$result = str_repeat(' ', $data_size);

		$key_index = $data_index = 0;

		while ($data_index < $data_size)
		{
			if ($key_index >= $key_size) 
				$key_index = 0;

			$result[$data_index] = chr((ord($data[$data_index]) + ord($strong_key[$key_index])) % 256);

			++$data_index;
			++$key_index;
		}

		return $result;
	}
	
	protected function deobfuscate_data(&$data, &$key)
	{
		$strong_key = md5($key);

		$result = str_repeat(' ', strlen($data));
		$key_size = strlen($strong_key);
		$data_size  = strlen($data);

		$key_index = $data_index = 0;

		while ($data_index < $data_size)
		{
			if ($key_index >= $key_size)
				$key_index = 0;
				
			$byte = ord($data[$data_index]) - ord($strong_key[$key_index]);
			if ($byte < 0) 
				$byte += 256;
				
			$result[$data_index] = chr($byte);
			++$data_index;
			++$key_index;
		}
		
		return $result;
	}
	
	protected function get_mode_descriptor()
	{
		if ($this->mode_descriptor == null)
			$this->mode_descriptor = mcrypt_module_open(MCRYPT_RIJNDAEL_256, null, MCRYPT_MODE_CBC, null);
			
		return $this->mode_descriptor;
	}
}
