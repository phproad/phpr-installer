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

date_default_timezone_set('Australia/Sydney');
ini_set('date.timezone', 'AEST');
ini_set('display_errors', 0);
error_reporting(0);


if (!defined('APP_NAME')) 
	define('APP_NAME', 'PHP Road');

if (!defined('PATH_INSTALL')) 
	define('PATH_INSTALL', str_replace("\\", "/", realpath(dirname(__FILE__)."/../../") ) );

include "install_config.php";
include "install_crypt.php";

$APP_CONF = array();
$PHPR_NO_SESSION = false;

class ValidationException extends Exception
{
	public $field;

	public function __construct($message, $field)
	{
		parent::__construct( $message );
		$this->field = $field;
	}
}

function get_request_uri()
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

function installer_root_url($target_url)
{
	if (substr($target_url, 0, 1) == '/')
		$target_url = substr($target_url, 1);
	
	$url = get_request_uri();
	$url = dirname($url);
	$url = str_replace('\\', '/', $url);
	
	if (substr($url, -1) != '/')
		$url .= '/';

	return $url.$target_url;
}

function strleft($s1, $s2) 
{
	return substr($s1, 0, strpos($s1, $s2));
}

function get_root_url($protocol = null)
{
	if ($protocol === null)
	{
		$s = empty($_SERVER["HTTPS"]) ? '' : ($_SERVER["HTTPS"] == "on") ? "s"  : "";
		$protocol = strleft(strtolower($_SERVER["SERVER_PROTOCOL"]), "/").$s;
	}

	$port = ($_SERVER["SERVER_PORT"] == "80") ? ""
		: (":".$_SERVER["SERVER_PORT"]);
		
	return $protocol."://".$_SERVER['SERVER_NAME'].$port;
}

function _post($name, $default = null)
{
	if (isset($_POST[$name]))
	{
		$result = $_POST[$name];

		if (get_magic_quotes_gpc())
			$result = stripslashes( $result );

		return $result;
	}

	return $default;
}

function _h($str)
{
	return htmlentities( $str, ENT_COMPAT, 'UTF-8' );
}

function Phpr_Installer::error_marker($error_field, $this_field)
{
	return $error_field == $this_field ? 'error' : null;
}

function render_partial($name, $params = array())
{
	$file = PATH_INSTALL.'/install_files/partials/'.$name.'.htm';
	if (!file_exists($file))
		throw new Exception("Partial not found: $name");

	extract($params);
	include $file;
}

function show_installer_step()
{
	$this_step = _post('step');

	switch ($this_step)
	{
		case 'welcome': 
			render_partial('requirements'); 
		break;

		case 'requirements': 
			render_partial('website_config'); 
		break;

		case 'website_config': 
			$error = false;
			try
			{
				$hash = validate_website_config();
			}
			catch (Exception $ex)
			{
				$error = $ex;
			}

			if ($error)
				render_partial('website_config', array('error'=>$error)); 
			else
				render_partial('system_config');
		break;

		case 'system_config':
			$error = false;
			try
			{
				validate_config_information();
			}
			catch (Exception $ex)
			{
				$error = $ex;
			}

			if ($error)
				render_partial('system_config', array('error'=>$error)); 
			else
				render_partial('admin_url');
		break;

		case 'admin_url':
			$error = false;
			try
			{
				validate_admin_url();
			}
			catch (Exception $ex)
			{
				$error = $ex;
			}

			if ($error)
				render_partial('admin_url', array('error'=>$error)); 
			else
				render_partial('permissions');
		break;

		case 'permissions': 
			$error = false;
			try
			{
				validate_permissions();
			}
			catch (Exception $ex)
			{
				$error = $ex;
			}

			if ($error)
				render_partial('permissions', array('error'=>$error)); 
			else
				render_partial('admin_user');
		break;

		case 'admin_user':
			$error = false;
			try
			{
				validate_admin_user();
			}
			catch (Exception $ex)
			{
				$error = $ex;
			}

			if ($error)
				render_partial('admin_user', array('error'=>$error)); 
			else
				render_partial('encryption_code');
		break;
		
		case 'encryption_code':
			$error = false;
			$delete_files_on_install = false;
			try
			{
				$delete_files_on_install = validate_encryption_code();
			}
			catch (Exception $ex)
			{
				$error = $ex;
			}

			if ($error)
				render_partial('encryption_code', array('error'=>$error)); 
			else
			{
				$files_deleted = !file_exists(PATH_INSTALL.'/install_files') && !file_exists(PATH_INSTALL.'/install.php');
				render_partial('complete', array('base_url'=>get_base_url()));
			}					
		break;
		
		default: 
			if (defined('PHPR_BS_INSTALL'))
				render_partial('website_config'); 
			else
				render_partial('welcome'); 
		break;
	}
}

function request_server_data($url, $fields = array())
{
	global $installer_config;
	
	$uc_url = $installer_config['AHOY_SERVER_URL'];

	if (!strlen($uc_url))
		throw new Exception(APP_NAME.' server URL is not specified in the configuration file.');

	$result = null;
	try
	{

		$poststring = array();

		foreach($fields as $key=>$val)
			$poststring[] = urlencode($key)."=".urlencode($val); 

		$poststring = implode('&', $poststring);

		$ch = curl_init(); 
		curl_setopt($ch, CURLOPT_URL, 'http://'.$uc_url.'/'.$url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $poststring);
		$result = curl_exec($ch);
	} 
	catch (Exception $ex) {}

	if (!$result || !strlen($result))
		throw new Exception("Error connecting to the ".APP_NAME." server.");

	$result_data = false;
	try
	{
		$result_data = @unserialize($result);
	} 
	catch (Exception $ex) 
	{
		throw new Exception("Invalid response from the ".APP_NAME." server.");
	}

	if ($result_data === false)
		throw new Exception("Invalid response from the ".APP_NAME." server.");
		
	if ($result_data['error'])
		throw new Exception($result_data['error']);
		
	return $result_data;
}

function validate_website_config()
{
	$holder_name = trim(_post('holder_name'));
	if (!strlen($holder_name))
		throw new ValidationException('Please enter license holder name.', 'holder_name');

	$installation_key = trim(_post('installation_key'));
	if (!strlen($installation_key))
		throw new ValidationException('Please enter the serial number.', 'installation_key');

	$hash = md5($installation_key.$holder_name);

	$data = array(
		'url'=>base64_encode(get_root_url())
	);

	$result = request_server_data('get_install_hashes/'.$hash, $data);
	if (!is_array($result['data']))
		throw new Exception("Invalid server response");

	$file_hashes = $result['data']['file_hashes'];
	$license_key = $result['data']['key'];
	$application_name = $result['data']['application_name'];
	$application_image = $result['data']['application_image'];
	$theme_code = $result['data']['theme_code'];
	$theme_name = $result['data']['theme_name'];

	$tmp_path = PATH_INSTALL.'/install_files/temp';
	if (!is_writable($tmp_path))
		throw new Exception("Cannot create temporary file. Path is not writable: ".$tmp_path);

	$files = array();
	try
	{
		
		foreach ($file_hashes as $code=>$file_hash)
		{
			$tmp_file = $tmp_path.'/'.$code.'.arc';
			$result = request_server_data('get_file/'.$hash.'/'.$code);

			$tmp_save_result = false;
			try
			{
				$tmp_save_result = @file_put_contents($tmp_file, $result['data']);
			}
			catch (Exception $ex)
			{
				throw new Exception("Error creating temporary file in ".$tmp_path);
			}
			
			$files[] = $tmp_file;
	
			if (!$tmp_save_result)
				throw new Exception("Error creating temporary file in ".$tmp_path);
		
			$downloaded_hash = md5_file($tmp_file);
			if ($downloaded_hash != $file_hash)
				throw new Exception("Downloaded archive is corrupted. Please try again.");

		}
	}
	catch (Exception $ex)
	{
		foreach ($files as $file)
		{
			if (file_exists($file))
				@unlink($file);
		}
		
		throw $ex;
	}

	$install_hashes = array(
		'hash'=>$hash,
		'key'=>$license_key,
		'holder'=>$holder_name
	);

	$app_info = array(
		'name' => $application_name,
		'image' => $application_image,
		'theme_code' => $theme_code,
		'theme_name' => $theme_name
	);
	Install_Crypt::create()->encrypt_to_file(PATH_INSTALL.'/install_files/temp/params3.dat', $install_hashes, _post('install_key'));
	Install_Crypt::create()->encrypt_to_file(PATH_INSTALL.'/install_files/temp/params6.dat', $file_hashes, _post('install_key'));		
	Install_Crypt::create()->encrypt_to_file(PATH_INSTALL.'/install_files/temp/params7.dat', $app_info, _post('install_key'));		
}

function validate_config_information()
{
	global $APP_CONF;
	
	$mysql_host = trim(_post('mysql_host'));
	$db_name = trim(_post('db_name'));
	$mysql_user = trim(_post('mysql_user'));
	$mysql_password = trim(_post('mysql_password'));
	$time_zone = trim(_post('time_zone'));

	if (!strlen($mysql_host))
		throw new ValidationException('Please specify MySQL host', 'mysql_host');
		
	/*
	 * Validate database connection
	 */
	
	if ( !@mysql_pconnect( $mysql_host, $mysql_user, $mysql_password ) )
		throw new ValidationException( 'Unable to connect to specified MySQL host. MySQL returned the following error: '.mysql_error().'.', 'mysql_host' );

	/*
	 * Validate database
	 */

	if (!strlen($db_name))
		throw new ValidationException('Please specify MySQL database name', 'db_name');
	
	if ( !mysql_select_db($db_name) )
		throw new ValidationException( 'Unable to select specified database "'.$db_name.'". MySQL returned the following error: '.mysql_error().'.', 'db_name' );

	/*
	 * Check whether the database is empty
	 */
	
	$result = @mysql_list_tables($db_name);
	$num = @mysql_num_rows($result);
	@mysql_free_result($result);

	if ($num)
		throw new ValidationException('Database "'.$db_name.'" is not empty. Please empty the database or specify another database.', 'db_name');

	$inst_params = array(
		'host'=>$mysql_host,
		'db_name'=>$db_name,
		'mysql_user'=>$mysql_user,
		'mysql_password'=>$mysql_password,
		'time_zone'=>$time_zone
	);

	Install_Crypt::create()->encrypt_to_file(PATH_INSTALL.'/install_files/temp/params.dat', $inst_params, _post('install_key'));
}	

function validate_admin_url()
{
	$backend_url = strtolower(trim(_post('backend_url')));
	
	if (!strlen($backend_url))
		throw new ValidationException('Please specify a URL key which you will use to access the Administration Area.', 'backend_url');

	if (!preg_match('/^[0-9a-z_]+$/i', $backend_url))
		throw new ValidationException('URL keys can contain only Latin characters, digits and underscore characters.', 'backend_url');

	$invalid_urls = array('config', 'modules', 'themes', 'uploaded', 'controllers', 'init', 'handlers', 'logs', 'framework', 'temp');
	foreach ($invalid_urls as $invalid_url)
	{
		if ($invalid_url == $backend_url)
			throw new ValidationException('Please do not use the following words as URL keys: '.implode(', ', $invalid_urls), 'backend_url');
	}
		
	$params = array(
		'backend_url'=>$backend_url
	);

	Install_Crypt::create()->encrypt_to_file(PATH_INSTALL.'/install_files/temp/params4.dat', $params, _post('install_key'));
}

function validate_permissions()
{
	$folder_mask = trim(_post('folder_mask'));
	$file_mask = trim(_post('file_mask'));

	if (!strlen($folder_mask))
		throw new ValidationException('Please specify folder permission mask', 'folder_mask');

	if (!strlen($file_mask))
		throw new ValidationException('Please specify user last name', 'file_mask');

	if (!preg_match("/^[0-9]{3}$/", $folder_mask) || $folder_mask > 777)
		throw new ValidationException('Please specify a valid folder permission mask', 'folder_mask');

	if (!preg_match("/^[0-9]{3}$/", $file_mask) || $file_mask > 777)
		throw new ValidationException('Please specify a valid file permission mask', 'file_mask');

	$params = array(
		'folder_mask'=>$folder_mask,
		'file_mask'=>$file_mask
	);

	Install_Crypt::create()->encrypt_to_file(PATH_INSTALL.'/install_files/temp/params5.dat', $params, _post('install_key'));
}

function validate_admin_user()
{
	$firstname = trim(_post('firstname'));
	$lastname = trim(_post('lastname'));
	$email = trim(_post('email'));
	
	$username = trim(_post('username'));
	$password = trim(_post('password'));
	$confirm = trim(_post('password_confirm'));

	if (!strlen($firstname))
		throw new ValidationException('Please specify user first name', 'firstname');

	if (!strlen($lastname))
		throw new ValidationException('Please specify user last name', 'lastname');

	if (!strlen($email))
		throw new ValidationException('Please specify user email address', 'email');
		
	if (!preg_match("/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$/", $email))
		throw new ValidationException('Please specify valid email address', 'email');

	if (!strlen($username))
		throw new ValidationException('Please specify user name', 'username');

	if (!strlen($password))
		throw new ValidationException('Please specify password', 'password');

	if (!strlen($confirm))
		throw new ValidationException('Please specify password confirmation', 'password_confirm');
		
	if ($password != $confirm)
		throw new ValidationException('The confirmation password does not match the password.', 'password_confirm');

	$params = array(
		'login'=>$username,
		'password'=>$password,
		'firstname'=>$firstname,
		'lastname'=>$lastname,
		'email'=>$email
	);

	Install_Crypt::create()->encrypt_to_file(PATH_INSTALL.'/install_files/temp/params2.dat', $params, _post('install_key'));
}

function validate_encryption_code()
{
	global $APP_CONF;
	global $Phpr_NoSession;		

	$enc_key = trim(_post('encryption_code'));
	$confirmation = trim(_post('confirmation'));

	if (!strlen($enc_key))
		throw new ValidationException('Please specify encryption key', 'encryption_code');
		
	if (strlen($enc_key) < 6)
		throw new ValidationException('The encryption key should be at least 6 characters in length.', 'encryption_code');
		
	if (!strlen($confirmation))
		throw new ValidationException('Please specify encryption key confirmation', 'confirmation');
		
	if ($enc_key != $confirmation)
		throw new ValidationException('The confirmation encryption key does not match the encryption key.', 'confirmation');
	
	/*
	 * Find existing .htaccess file and check whether it defines the PHP5 handler
	 */

	$php5_handler = null;
	$original_htaccess_contents = null;
	if (file_exists(PATH_INSTALL.'/.htaccess'))
	{
		$original_htaccess_contents = $ht_contents = file_get_contents(PATH_INSTALL.'/.htaccess');
		$matches = array();
		if (preg_match('/AddHandler\s+(.*)\s+\.php/im', $ht_contents, $matches))
			$php5_handler = trim($matches[0]);
	}

	/*
	 * Install the application
	 */

	require PATH_INSTALL.'/install_files/libs/ziphelper.php';

	try
	{
		$crypt = Install_Crypt::create();
		$permission_params = $crypt->decrypt_from_file(PATH_INSTALL.'/install_files/temp/params5.dat', _post('install_key'));

		$file_permissions_octal = '0'.$permission_params['file_mask'];
		$folder_permissions_octal = '0'.$permission_params['folder_mask'];

		$file_permissions = eval('return '.$file_permissions_octal.';');
		$folder_permissions = eval('return '.$folder_permissions_octal.';');

		$path = PATH_INSTALL.'/install_files/temp';
		$d = dir($path);
		while ( false !== ($entry = $d->read()) ) 
		{
			$file_path = $path.'/'.$entry;

			if ($entry == '.' || $entry == '..' || is_dir($file_path) || substr($file_path, -4) != '.arc')
				continue;

			$zip_file_permissions = $file_permissions;
			$zip_folder_permissions = $folder_permissions;
			ZipHelper::unzip(PATH_INSTALL, $file_path, $zip_file_permissions, $zip_folder_permissions);
		}

		$d->close();

		$dir_permissions = $folder_permissions;
		set_dir_access(PATH_INSTALL.'/temp', $dir_permissions);
		
		/*
		 * Generate the config file
		 */

		$system_params = $crypt->decrypt_from_file(PATH_INSTALL.'/install_files/temp/params.dat', _post('install_key'));
		$url_params = $crypt->decrypt_from_file(PATH_INSTALL.'/install_files/temp/params4.dat', _post('install_key'));

		$config = file_get_contents(PATH_INSTALL.'/install_files/config.tpl');			

		$config = str_replace('%TIMEZONE%', $system_params['time_zone'], $config);
		$config = str_replace('%FILEPERM%', $file_permissions_octal, $config);
		$config = str_replace('%FOLDERPERM%', $folder_permissions_octal, $config);
		$config = str_replace('%ADMIN_URL%', '/'.$url_params['backend_url'], $config);
		$config = str_replace('%DATABASEHOST%', $system_params['host'], $config);
		$config = str_replace('%DATABASEUSER%', $system_params['mysql_user'], $config);
		$config = str_replace('%DATABASEPASS%', $system_params['mysql_password'], $config);
		$config = str_replace('%DATABASENAME%', $system_params['db_name'], $config);
		
		$config_path = PATH_INSTALL.'/config/config.php';
		if (!is_writable(PATH_INSTALL.'/config'))
			throw new Exception('Unable to create configuration file: '.$config_path.' - the config directory is not writable for PHP. Please try to use a less restrictive folder permission mask. You will need to empty the installation directory and restart the installer.');
		
		if (@file_put_contents($config_path, $config) === false)
			throw new Exception('Unable to create configuration file: '.$config_path);				

		/*
		 * Generate the index.php file
		 */
		
		$index_path = PATH_INSTALL.'/index.php';
		if (!file_exists($index_path))
		{
			$index_content = file_get_contents(PATH_INSTALL.'/install_files/index.tpl');
			if (@file_put_contents($index_path, $index_content) === false)
				throw new Exception('Unable to create index.php file: '.$index_content);

		}	

		/*
		 * Create .htaccess file
		 */
		
		if (!@copy(PATH_INSTALL.'/install_files/htaccess.tpl', PATH_INSTALL.'/.htaccess'))
			throw new Exception('Unable to create the .htaccess file: '.PATH_INSTALL.'/.htaccess');
			
		if ($php5_handler)
		{
			$ht_contents = file_get_contents(PATH_INSTALL.'/.htaccess');
			$ht_contents = $php5_handler."\n\n".$ht_contents;
			@file_put_contents(PATH_INSTALL.'/.htaccess', $ht_contents);
		}

		/*
		 * Create required directories and files
		 */

		installer_make_dir(PATH_INSTALL.'/uploaded/public', $dir_permissions);
		installer_make_dir(PATH_INSTALL.'/uploaded/thumbnails', $dir_permissions);

		/*
		 * Create database objects
		 */
		
		$APP_CONF = array();

		$PHPR_INIT_ONLY = true;
		include PATH_INSTALL.'/index.php';

		$admin_user_params = $crypt->decrypt_from_file(PATH_INSTALL.'/install_files/temp/params2.dat', _post('install_key'));
		$install_hashes = $crypt->decrypt_from_file(PATH_INSTALL.'/install_files/temp/params3.dat', _post('install_key'));
		$file_hashes = $crypt->decrypt_from_file(PATH_INSTALL.'/install_files/temp/params6.dat', _post('install_key'));

		$delete_files_on_install = false;

		$license_hash = $install_hashes['hash'];
		$license_key = $install_hashes['key'];
		$license_holder = $install_hashes['holder'];			

		// Connect to database
		$framework = Phpr_SecurityFramework::create();
		
		$config_content = array(
			'config_key' => $enc_key,
			'hash' => $license_hash,
			'license_key' => $license_key
		);
		
		$framework->set_config_content($config_content);
		$framework->reset_instance();

		$framework = Phpr_SecurityFramework::create()->reset_instance();

		Db_Update_Manager::update();
		Db_Module_Parameters::set('core', 'hash', base64_encode($framework->encrypt($license_hash)));
		Db_Module_Parameters::set('core', 'license_key', $license_key);			

		/*
		 * Create administrator account
		 */
		
		$user = new Admin_User();
		$user->first_name = $admin_user_params['firstname'];
		$user->last_name = $admin_user_params['lastname'];
		$user->email = $admin_user_params['email'];
		$user->login = $admin_user_params['login'];
		$user->password = $admin_user_params['password'];
		$user->password_confirm = $admin_user_params['password'];

		$user->save();
		
		Db_DbHelper::query("insert into admin_groups_users(admin_user_id, admin_group_id) values(LAST_INSERT_ID(), (select id from admin_groups where code='administrator'))");


		/*
		 * Create the default theme
		 */

		$app_info = $crypt->decrypt_from_file(PATH_INSTALL.'/install_files/temp/params7.dat', _post('install_key'));
		$theme = new Cms_Theme();
		$theme->name = $app_info['theme_name'];
		$theme->code = $app_info['theme_code'];
		$theme->description = "Default theme for ".$app_info['name'];
		$theme->author_website = "http://scriptsahoy.com/";
		$theme->author_name = APP_NAME;
		$theme->default_theme = true;
		$theme->enabled = true;
		$theme->save();
		
		Cms_Theme::auto_create_all_from_files();

		/*
		 * Finalize installation
		 */

		install_cleanup();

		return $delete_files_on_install;
	}
	catch (Exception $ex)
	{
		$ht_file = PATH_INSTALL.'/.htaccess';
		if (file_exists($ht_file))
			@unlink($ht_file);

		if ($original_htaccess_contents)
			@file_put_contents($ht_file, $original_htaccess_contents);
	
		throw $ex;
	}
}

function installer_make_dir($path, $permissions)
{
	if (!file_exists($path))
		@mkdir($path);
	
	@chmod($path, $permissions);
}

function installer_remove_dir($sDir) 
{
	if (is_dir($sDir)) 
	{
		$sDir = rtrim($sDir, '/');
		$oDir = dir($sDir);
		
		while (($sFile = $oDir->read()) !== false) 
		{
			if ($sFile != '.' && $sFile != '..') 
				(!is_link("$sDir/$sFile") && is_dir("$sDir/$sFile")) ? installer_remove_dir("$sDir/$sFile") : @unlink("$sDir/$sFile");
		}
		$oDir->close();
		
		@rmdir($sDir);
		return true;
	}

	return false;
}


function check_requirements()
{
	$result = array();
	
	$result['PHP 5.2.5 or higher'] = version_compare(PHP_VERSION , "5.2.5", ">=");
	$result['PHP CURL library'] = function_exists('curl_init');		
	$result['PHP Mcrypt library'] = function_exists('mcrypt_encrypt');
	$result['PHP MySQL functions'] = function_exists('mysql_connect');		
	$result['Permissions for PHP to write to the installation directory'] = is_writable(PATH_INSTALL);
	$result['PHP Multibyte String functions'] = function_exists('mb_convert_encoding');
	$result['Short PHP tags allowed'] = ini_get('short_open_tag');

	if (ini_get('safe_mode'))
		$result['PHP Safe Mode detected '] = false;
	
	return $result;
}


function gen_install_key()
{
	$letters = 'abcdefghijklmnopqrstuvwxyz';
	$result = null;
	for ($i = 1; $i <= 6; $i++)
		$result .= $letters[rand(0,25)];

	return md5($result.time());
}

function install_cleanup()
{
	$path = PATH_INSTALL.'/install_files/temp';
	if (!file_exists($path))
		return;

	$d = dir($path);
	while ( false !== ($entry = $d->read()) ) 
	{
		$file_path = $path.'/'.$entry;
		
		if ($entry == '.' || $entry == '..' || $entry == '.htaccess' || is_dir($file_path))
			continue;

		@unlink($file_path);
	}

	$d->close();
}

function set_dir_access($path, $permissions)
{
	$path .= '/.htaccess';
	
	$data = "order deny,allow\ndeny from all";
	@file_put_contents($path, $data);
	
	@chmod($path, $permissions);
}

function output_install_page()
{
	try
	{
		show_installer_step();
	} 
	catch (Exception $ex)
	{
		install_cleanup();
		render_partial('exception', array('exception'=>$ex));
	}
}

function get_application_info()
{
	
	if (!_post('install_key'))
		return null;

	try 
	{
		return Install_Crypt::create()->decrypt_from_file(PATH_INSTALL.'/install_files/temp/params7.dat', _post('install_key'));		
	} 
	catch (Exception $e)
	{
		return null;
	}

	return null;
}

function get_base_url()
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