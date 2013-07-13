<?php

header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (function_exists('set_magic_quotes_runtime'))
	@set_magic_quotes_runtime(0);

if (!ini_get('safe_mode'))
	set_time_limit(3600);

date_default_timezone_set('GMT');
ini_set('date.timezone', 'GMT');
ini_set('display_errors', 0);
error_reporting(0);

include "phpr_installer_lang.php";
include "phpr_installer_crypt.php";
include "phpr_installer_manager.php";
include "zip_helper.php";

class Phpr_Installer 
{
	public static function create()
	{
		return new self();
	}

	public function display_head()
	{
		$str = array();
		$str[] = '<link href="http://fonts.googleapis.com/css?family=Ubuntu" rel="stylesheet" type="text/css">';
		$str[] = '<link rel="stylesheet" href="'.self::find_public_path(PATH_INSTALL_APP.'/install_files/assets/css/installer.css').'" type="text/css" />';
		$str[] = '<script src="'.self::find_public_path(PATH_INSTALL_APP.'/install_files/assets/javascript/jquery.js').'"></script>';
		$str[] = '<script src="'.self::find_public_path(PATH_INSTALL_APP.'/install_files/assets/javascript/progressbar.js').'"></script>';
		$str[] = '<script src="'.self::find_public_path(PATH_INSTALL_APP.'/install_files/assets/javascript/download.js').'"></script>';
		return implode(PHP_EOL, $str);
	}

	public function check_requirements()
	{
		$result = array();
		
		$result['PHP 5.3 or higher'] = version_compare(PHP_VERSION , "5.3", ">=");
		$result['PHP CURL library'] = function_exists('curl_init');     
		$result['PHP Mcrypt library'] = function_exists('mcrypt_encrypt');
		$result['PHP MySQL functions'] = function_exists('mysql_connect');      
		$result['Permissions for PHP to write to the installation directory'] = is_writable(PATH_INSTALL);
		$result['PHP Multibyte String functions'] = function_exists('mb_convert_encoding');
		$result['Short PHP tags allowed'] = ini_get('short_open_tag');

		if (ini_get('safe_mode'))
			$result['PHP Safe Mode detected'] = false;
		
		return $result;
	}

	public function check_remote_event()
	{
		return isset($_SERVER['HTTP_PHPR_REMOTE_EVENT']);
	}

	public function throw_fatal_error($message)
	{
		if ($this->check_remote_event()) {
			header('HTTP/1.1 500 Internal Server Error');
			header('Content-Type: text/plain');  
			echo $message;
		}
		else {
			$this->render_partial('exception', array('message'=>$message));
		}
	}

	public function output_install_page()
	{
		try
		{
			$this->show_installer_step();
		} 
		catch (Exception $ex)
		{
			// @TODO
			//Phpr_Installer_Manager::install_cleanup();
			
			$this->throw_fatal_error($ex->getMessage());
		}
	}

	public function show_installer_step()
	{
		$this_step = self::post('step');
		switch ($this_step)
		{
			// AJAX actions
			// 
			
			case 'request_package':
				$package_name = self::post('package_name');
				$package_type = self::post('package_type');
				$core_modules = $this->get_file_hashes($package_type);
				$hash = $this->get_hash();
				
				if (!strlen(trim($package_name)))
					throw new Exception('No packages to install');

				if (!isset($core_modules[$package_name]))
					throw new Exception('Unknown package with name '.$package_name);

				Phpr_Installer_Manager::download_package($hash, $package_name, $package_type, $core_modules[$package_name]);
			break;

			case 'unzip_package':
				$package_name = self::post('package_name');
				$package_type = self::post('package_type');
				$core_modules = $this->get_file_hashes($package_type);

				if (!strlen(trim($package_name)))
					throw new Exception('No packages to to process');

				if (!isset($core_modules[$package_name]))
					throw new Exception('Unknown package with name '.$package_name);
				
				Phpr_Installer_Manager::unzip_package($package_name, $package_type);
			break;

			case 'install_phpr':
				$action = self::post('action');
				Phpr_Installer_Manager::$install_key = self::post('install_key');
	
				switch ($action)
				{
					case 'generate_files':
						Phpr_Installer_Manager::generate_config_file();
						Phpr_Installer_Manager::generate_index_file();
					break;
					case 'build_database':
						Phpr_Installer_Manager::build_database();
					break;
					case 'create_admin':
						Phpr_Installer_Manager::create_admin_account();
					break;
					case 'install_theme':
						Phpr_Installer_Manager::create_default_theme();
					break;
				}
			break;

			// Controller steps
			// 
			
			default: 
				$this->render_partial('requirements'); 
			break;

			case 'welcome': 
				$terms_content = nl2br(Phpr_Installer::h(file_get_contents(PATH_INSTALL_APP.'/licence.txt')));
				$this->render_partial('terms', array('terms_content' => $terms_content)); 
			break;

			case 'requirements': 
			case 'install':
				$this->render_partial('website_config'); 
			break;

			case 'website_config': 
				$error = false;
				try
				{
					$install_params = Phpr_Installer_Manager::validate_website_config(
						trim(self::post('license_name')),
						trim(self::post('installation_key')),
						trim(self::post('generate_key'))
					);

					Phpr_Installer_Crypt::create()->encrypt_to_file(
						PATH_INSTALL_APP.'/temp/params1.dat', 
						$install_params, 
						self::post('install_key')
					);
				}
				catch (Exception $ex)
				{
					$error = $ex;
				}

				if ($error)
					$this->render_partial('website_config', array('error' => $error)); 
				else
					$this->render_partial('database_config');
			break;

			case 'database_config':
				$error = false;
				try
				{
					$install_params = Phpr_Installer_Manager::validate_database_config(
						trim(self::post('mysql_host')), 
						trim(self::post('db_name')), 
						trim(self::post('mysql_user')), 
						trim(self::post('mysql_password'))
					);
					
					Phpr_Installer_Crypt::create()->encrypt_to_file(
						PATH_INSTALL_APP.'/temp/params2.dat', 
						$install_params, 
						self::post('install_key')
					);
				}
				catch (Exception $ex)
				{
					$error = $ex;
				}

				if ($error)
					$this->render_partial('database_config', array('error' => $error)); 
				else
					$this->render_partial('admin_url');
			break;

			case 'admin_url':
				$error = false;
				try
				{
					$install_params = Phpr_Installer_Manager::validate_admin_url(
						strtolower(trim(self::post('admin_url')))
					);
					
					Phpr_Installer_Crypt::create()->encrypt_to_file(
						PATH_INSTALL_APP.'/temp/params3.dat', 
						$install_params, 
						self::post('install_key')
					);
				}
				catch (Exception $ex)
				{
					$error = $ex;
				}

				if ($error)
					$this->render_partial('admin_url', array('error' => $error)); 
				else
					$this->render_partial('system_config');
			break;

			case 'system_config': 
				$error = false;
				try
				{
					$install_params = Phpr_Installer_Manager::validate_system_config(
						trim(self::post('folder_mask')),
						trim(self::post('file_mask')), 
						trim(self::post('time_zone')) 
					);
					
					Phpr_Installer_Crypt::create()->encrypt_to_file(
						PATH_INSTALL_APP.'/temp/params4.dat', 
						$install_params, 
						self::post('install_key')
					);
				}
				catch (Exception $ex)
				{
					$error = $ex;
				}

				if ($error)
					$this->render_partial('system_config', array('error' => $error)); 
				else
					$this->render_partial('admin_user');
			break;

			case 'admin_user':
				$error = false;
				try
				{
					$install_params = Phpr_Installer_Manager::validate_admin_user(
						trim(self::post('firstname')),
						trim(self::post('lastname')),
						trim(self::post('email')),
						trim(self::post('username')),
						trim(self::post('password')),
						trim(self::post('password_confirm'))
					);
					
					Phpr_Installer_Crypt::create()->encrypt_to_file(
						PATH_INSTALL_APP.'/temp/params5.dat', 
						$install_params, 
						self::post('install_key')
					);
				}
				catch (Exception $ex)
				{
					$error = $ex;
				}

				if ($error)
					$this->render_partial('admin_user', array('error' => $error)); 
				else
					$this->render_partial('encryption_code');
			break;
			
			case 'encryption_code':
				$error = false;
				try
				{
					$install_params = Phpr_Installer_Manager::validate_encryption_code(
						trim(self::post('encryption_code')),
						trim(self::post('confirmation'))
					);
					
					Phpr_Installer_Crypt::create()->encrypt_to_file(
						PATH_INSTALL_APP.'/temp/params6.dat', 
						$install_params, 
						self::post('install_key')
					);
				}
				catch (Exception $ex)
				{
					$error = $ex;
				}

				if ($error)
					$this->render_partial('encryption_code', array('error' => $error)); 
				else
				{
					$this->render_partial('download_packages');
				}
			break;

			case 'download_packages':
				try
				{
					Phpr_Installer_Manager::install_finish();

					$files_deleted = !file_exists(PATH_INSTALL_APP.'') && !file_exists(PATH_INSTALL.'/install.php');
					$params = array(
						'base_url' => $this->get_base_url(), 
						'files_deleted' => $files_deleted
					);
					$this->render_partial('complete', $params);
				}
				catch (Exception $ex)
				{
					$this->throw_fatal_error($ex->getMessage());
				}
			break;
		}
	}

	// Services
	// 

	public function get_file_hashes($type)
	{
		$crypt = Phpr_Installer_Crypt::create();
		$params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params1.dat', self::post('install_key'));
		$package_info = (isset($params['package_info'])) ? $params['package_info'] : array();
		$file_hashes = array();
		foreach ($package_info as $package) {

			if ($package->type != $type)
				continue;

			$code = $package->short_code;
			$file_hashes[$code] = $package->hash;
		}
		return $file_hashes;
	}

	public function get_package_info($name, $type) 
	{
		$crypt = Phpr_Installer_Crypt::create();
		$params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params1.dat', self::post('install_key'));
		$package_info = (isset($params['package_info'])) ? $params['package_info'] : array();

		foreach ($package_info as $package) {
			if ($package->type != $type)
				continue;

			if ($package->short_code == $name)
				return $package;
		}
		return null;
	}

	public function get_hash()
	{
		$crypt = Phpr_Installer_Crypt::create();
		$params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params1.dat', self::post('install_key'));
		return (isset($params['hash'])) ? $params['hash'] : array();
	}

	public function get_file_permissions()
	{
		$crypt = Phpr_Installer_Crypt::create();
		$system_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params4.dat', self::post('install_key'));
		
		$file_permissions_octal = '0'.$system_params['file_mask'];
		$folder_permissions_octal = '0'.$system_params['folder_mask'];
		$file_permissions = eval('return '.$file_permissions_octal.';');
		$folder_permissions = eval('return '.$folder_permissions_octal.';');

		return array(
			'file_permissions'         => $file_permissions, 
			'folder_permissions'       => $folder_permissions,
			'file_permissions_octal'   => $file_permissions_octal,
			'folder_permissions_octal' => $folder_permissions_octal
		);		
	}

	// Helpers
	// 

	public static function get_request_uri()
	{
		$providers = array('REQUEST_URI', 'PATH_INFO', 'ORIG_PATH_INFO');
		foreach ($providers as $provider)
		{
			$val = getenv($provider);
			if ($val != '')
				return $val;
		}
		
		return null;
	}

	public static function installer_root_url($target_url)
	{
		if (substr($target_url, 0, 1) == '/')
			$target_url = substr($target_url, 1);
		
		$url = self::get_request_uri();
		$url = dirname($url);
		$url = str_replace('\\', '/', $url);
		
		if (substr($url, -1) != '/')
			$url .= '/';

		return $url.$target_url;
	}

	public function strleft($s1, $s2) 
	{
		return substr($s1, 0, strpos($s1, $s2));
	}

	public static function find_public_path($path)
	{
		$result = null;
		if (strpos($path, PATH_INSTALL) === 0)
			$result = str_replace("\\", "/", substr($path, strlen(PATH_INSTALL)));

		return self::installer_root_url($result);
	}

	public function get_root_url($protocol = null)
	{
		if ($protocol === null)
		{
			$s = empty($_SERVER["HTTPS"]) ? '' : ($_SERVER["HTTPS"] == "on") ? "s"  : "";
			$protocol = $this->strleft(strtolower($_SERVER["SERVER_PROTOCOL"]), "/").$s;
		}

		$port = ($_SERVER["SERVER_PORT"] == "80") ? ""
			: (":".$_SERVER["SERVER_PORT"]);
			
		return $protocol."://".$_SERVER['SERVER_NAME'].$port;
	}

	public static function post($name, $default = null)
	{
		if (isset($_POST[$name]))
		{
			$result = $_POST[$name];

			if (get_magic_quotes_gpc())
				$result = stripslashes($result);

			return $result;
		}

		return $default;
	}

	public static function lang($code, $default = null)
	{
		global $PHPR_INSTALLER_LANG;

		return (isset($PHPR_INSTALLER_LANG[$code]))
			? $PHPR_INSTALLER_LANG[$code]
			: $default;
	}

	public static function h($str)
	{
		return htmlentities($str, ENT_COMPAT, 'UTF-8');
	}

	public static function error_marker($error_field, $this_field)
	{
		return $error_field == $this_field ? 'error' : null;
	}

	public function render_partial($name, $params = array())
	{
		$file = PATH_INSTALL_APP.'/install_files/partials/'.$name.'.htm';
		if (!file_exists($file))
			throw new Exception("Partial not found: ".$name);

		extract($params);
		include $file;
	}

	public function gen_install_key()
	{
		$letters = 'abcdefghijklmnopqrstuvwxyz';
		$result = null;
		for ($i = 1; $i <= 6; $i++)
			$result .= $letters[rand(0,25)];

		return md5($result.time());
	}

	public function get_base_url()
	{
		if (isset($_SERVER['HTTP_HOST']))
		{
			$base_url = isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
			$base_url .= '://'. $_SERVER['HTTP_HOST'];
			$base_url .= str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
		}
		else
		{
			$base_url = 'http://localhost/';
		}
		return $base_url;
	}

	public function detect_cli()
	{
		if (php_sapi_name() == 'cli')
			return true;
	
		if (!array_key_exists('DOCUMENT_ROOT', $_SERVER) || !strlen($_SERVER['DOCUMENT_ROOT']))
			return true;

		return false;
	}	
}

class ValidationException extends Exception
{
	public $field;

	public function __construct($message, $field)
	{
		parent::__construct($message);
		$this->field = $field;
	}
}

register_shutdown_function('phpr_installer_shutdown');
function phpr_installer_shutdown() {
	$error = error_get_last();
	if ($error['type'] == 1) {
		$installer = Phpr_Installer::create();
		$installer->throw_fatal_error($error['message']);
	} 
}