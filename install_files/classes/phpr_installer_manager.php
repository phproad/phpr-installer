<?php

class Phpr_Installer_Manager
{
	public static $install_key = null;

	const uri_get_install_package = 'install/package/get';
	const uri_get_install_file = 'install/file/get';

	/**
	 * Package Specific
	 */ 
	
	public static function get_package_list($type)
	{
		$installer = Phpr_Installer::create();
		return "'" . implode("','", array_keys($installer->get_file_hashes($type))) . "'";
	}

	public static function download_package($hash, $name, $type, $expected_hash)
	{
		$params = array(
			'hash' => $hash,
			'code' => $name,
			'type' => $type
		);

		$tmp_file = self::get_package_file_path($name, $type);
		$result = self::request_gateway_data(self::uri_get_install_file, $params);

		if (!isset($result->data) || !$result->data) 
			throw new Exception('Unable to download package file: '. $name);

		$file_data = base64_decode($result->data);

		$tmp_save_result = false;
		try
		{
			$tmp_save_result = @file_put_contents($tmp_file, $file_data);
		}
		catch (Exception $ex)
		{
			throw new Exception('Unable create temporary file in '.$tmp_file);
		}

		if (!$tmp_save_result)
			throw new Exception('Unable create temporary file in '.$tmp_file);

		$downloaded_hash = md5_file($tmp_file);

		if ($downloaded_hash != $expected_hash) {
			
			if (file_exists($tmp_file))
				@unlink($tmp_file);

			throw new Exception('Downloaded archive is corrupt. Please try the install again.');
		}

		return $tmp_file;
	}

	public static function unzip_package($name, $type)
	{
		$name = strtolower($name);
		$installer = Phpr_Installer::create();
		$package_info = $installer->get_package_info($name, $type);
		if (!$package_info)
			throw new Exception('Unable to find package information for '. $name);

		$code = $package_info->short_code;
		$tmp_file = self::get_package_file_path($name, $type);
		$tmp_path = dirname($tmp_file).DS.$type.'-'.$name.DS;

		if (!file_exists($tmp_path))
			@mkdir($tmp_path);

		if (!is_writable($tmp_path))
			throw new Exception('Unable to write to path '.$tmp_path);

		self::extract_file($tmp_file, $tmp_path);

		// Handle module
		if ($package_info->type == 'module') {
	
			$module_info_file = self::find_file($tmp_path, $code.'_module.php');
			if (!$module_info_file)
				throw new Exception('Package '.$name.' does not appear to be a valid module.');

			if ($code != 'core') {
				$source_dir = dirname(dirname($module_info_file));
				$target_dir = PATH_INSTALL.DS.'modules'.DS.$code;
			} else {
				// Core package must contain the bootstrap/framework files
				$source_dir = dirname(dirname(dirname(dirname($module_info_file))));
				$target_dir = PATH_INSTALL;
			}
		}

		// Handle theme
		elseif ($package_info->type == 'theme') {
			$source_dir = $tmp_path;
			$target_dir = PATH_INSTALL.DS.'themes'.DS.$code;
		}

		extract($installer->get_file_permissions());

		if (!file_exists($target_dir))
			@mkdir($target_dir, $folder_permissions, true);

		self::copy_directory($source_dir, $target_dir);
		self::delete_recursive($tmp_path);
		@unlink($tmp_file);
	}

	public static function get_package_file_path($name, $type)
	{
		$tmp_path = PATH_INSTALL_APP.DS.'temp';

		if (!file_exists($tmp_path))
			@mkdir($tmp_path);

		if (!is_writable($tmp_path))
			throw new Exception("Unable to write to path ".$tmp_path);
		
		$tmp_file = $tmp_path.DS.strtolower($type).'-'.strtolower($name).'.arc';

		return $tmp_file;
	}

	/**
	 * Gateway Specific 
	 */

	public static function request_gateway_data($url, $params = array())
	{
		$result = self::request_server_data(URL_GATEWAY.'/'.$url, $params);
		$result_data = false;

		try
		{
			$result_data = @json_decode($result);
		} 
		catch (Exception $ex) 
		{
			throw new Exception("Invalid response from the ".APP_NAME." server.");
		}
		if ($result_data === false)
			throw new Exception("Invalid response from the ".APP_NAME." server.");

		if (isset($result_data->error) && $result_data->error)
			throw new Exception($result_data->error);
			
		return $result_data;
	}

	public function validate_website_config($license_name, $installation_key, $generate_key)
	{
		if (!strlen($license_name))
			throw new ValidationException(Phpr_Installer::lang('ERROR_LICENSE_NAME'), 'license_name');

		if (!strlen($installation_key) && (defined('DISABLE_KEYLESS_ENTRY') || !$generate_key))
			throw new ValidationException(Phpr_Installer::lang('ERROR_INSTALLATION_KEY'), 'installation_key');

		if ($generate_key)
			$hash = 'keyless';
		else
			$hash = md5($installation_key.$license_name);

		$params = array(
			'hash' => $hash,
			'url' => base64_encode($this->get_root_url())
		);

		$result = self::request_gateway_data(self::uri_get_install_package, $params);
		if (!$result->data)
			throw new Exception("Invalid server response");

		$data = $result->data;

		$name = $data->name;
		$birthmark = $data->birthmark;
		$package_info = $data->package_info;
		$license_key = $data->key;
		$application_name = $data->application_name;
		$application_image = $data->application_image;
		$theme_code = $data->theme_code;
		$theme_name = $data->theme_name;
		$vendor_name = $data->vendor_name;
		$vendor_url = $data->vendor_url;

		$install_params = array(
			'name'         => $name,
			'birthmark'    => $birthmark,
			'hash'         => $hash,
			'key'          => $license_key,
			'app_name'     => $application_name,
			'app_image'    => $application_image,
			'theme_name'   => $theme_name,
			'theme_code'   => $theme_code,
			'vendor_name'  => $vendor_name,
			'vendor_url'   => $vendor_url,
			'package_info'  => (array)$package_info
		);

		return $install_params;
	}

	/**
	 * Database
	 */

	public function validate_database_config($mysql_host, $db_name, $mysql_user, $mysql_password)
	{
		if (!strlen($mysql_host))
			throw new ValidationException('Please specify MySQL host', 'mysql_host');
			
		if (!@mysql_pconnect($mysql_host, $mysql_user, $mysql_password))
			throw new ValidationException('Unable to connect to specified MySQL host. MySQL returned the following error: '.mysql_error(), 'mysql_host');

		if (!strlen($db_name))
			throw new ValidationException('Please specify MySQL database name', 'db_name');
		
		if (!mysql_select_db($db_name))
			throw new ValidationException('Unable to select specified database "'.$db_name.'". MySQL returned the following error: '.mysql_error(), 'db_name');

		// Database empty check
		$result = @mysql_list_tables($db_name);
		$num = @mysql_num_rows($result);
		@mysql_free_result($result);

		if ($num)
			throw new ValidationException('Database "'.$db_name.'" is not empty. Please empty the database or specify another database.', 'db_name');

		$install_params = array(
			'host'           => $mysql_host,
			'db_name'        => $db_name,
			'mysql_user'     => $mysql_user,
			'mysql_password' => $mysql_password
		);

		return $install_params;
	}   

	/**
	 * Other Validations
	 */
	
	public static function validate_admin_url($admin_url)
	{
		if (!strlen($admin_url))
			throw new ValidationException('Please specify a URL which you will use to access the Administration Area.', 'admin_url');

		if (!preg_match('/^[0-9a-z_]+$/i', $admin_url))
			throw new ValidationException('URL can contain only Latin characters, digits and underscore characters.', 'admin_url');

		$invalid_urls = array('config', 'modules', 'themes', 'uploaded', 'controllers', 'init', 'handlers', 'logs', 'framework', 'temp');
		foreach ($invalid_urls as $invalid_url)
		{
			if ($invalid_url == $admin_url)
				throw new ValidationException('Please do not use the following words for the URL: '.implode(', ', $invalid_urls), 'admin_url');
		}
			
		$install_params = array(
			'admin_url' => $admin_url
		);

		return $install_params;
	}

	public static function validate_system_config($folder_mask, $file_mask, $time_zone)
	{
		if (!strlen($folder_mask))
			throw new ValidationException('Please specify folder permission mask', 'folder_mask');

		if (!strlen($file_mask))
			throw new ValidationException('Please specify file permission mask', 'file_mask');

		if (!preg_match("/^[0-9]{3}$/", $folder_mask) || $folder_mask > 777)
			throw new ValidationException('Please specify a valid folder permission mask', 'folder_mask');

		if (!preg_match("/^[0-9]{3}$/", $file_mask) || $file_mask > 777)
			throw new ValidationException('Please specify a valid file permission mask', 'file_mask');

		if (!strlen($time_zone))
			throw new ValidationException('Please specify a time zone', 'time_zone');

		$install_params = array(
			'folder_mask' => $folder_mask,
			'file_mask'   => $file_mask,
			'time_zone'   => $time_zone
		);

		return $install_params;
	}

	public static function validate_admin_user($firstname, $lastname, $email, $username, $password, $confirm)
	{
		if (!strlen($firstname))
			throw new ValidationException('Please specify user first name', 'firstname');

		if (!strlen($lastname))
			throw new ValidationException('Please specify user last name', 'lastname');

		if (!strlen($email))
			throw new ValidationException('Please specify user email address', 'email');
			
		if (!preg_match("/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$/", $email))
			throw new ValidationException('Please specify valid email address', 'email');

		if (!strlen($username))
			throw new ValidationException('Please specify username', 'username');

		if (!strlen($password))
			throw new ValidationException('Please specify password', 'password');

		if (!strlen($confirm))
			throw new ValidationException('Please specify password confirmation', 'password_confirm');
			
		if ($password != $confirm)
			throw new ValidationException('The confirmation password does not match the password.', 'password_confirm');

		$install_params = array(
			'login'     => $username,
			'password'  => $password,
			'firstname' => $firstname,
			'lastname'  => $lastname,
			'email'     => $email
		);

		return $install_params;
	}

	public function validate_encryption_code($enc_key, $confirmation)
	{
		if (!strlen($enc_key))
			throw new ValidationException('Please specify encryption key', 'encryption_code');
			
		if (strlen($enc_key) < 6)
			throw new ValidationException('The encryption key should be at least 6 characters in length.', 'encryption_code');
			
		if (!strlen($confirmation))
			throw new ValidationException('Please specify encryption key confirmation', 'confirmation');
			
		if ($enc_key != $confirmation)
			throw new ValidationException('The confirmation encryption key does not match the encryption key.', 'confirmation');
		
		$install_params = array(
			'enc_key' => $enc_key
		);
		return $install_params;
	}

	/**
	 * Installation Logic
	 */

	// Generate config file
	public static function generate_config_file()
	{
		$crypt = Phpr_Installer_Crypt::create();
		$installer = Phpr_Installer::create();
		$db_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params2.dat', self::$install_key);
		$url_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params3.dat', self::$install_key);
		$system_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params4.dat', self::$install_key);
		extract($installer->get_file_permissions());

		$config = file_get_contents(PATH_INSTALL_APP.'/install_files/templates/config.tpl');

		$config = str_replace('%APP_NAME%', APP_NAME, $config);
		$config = str_replace('%TIMEZONE%', $system_params['time_zone'], $config);
		$config = str_replace('%FILEPERM%', $file_permissions_octal, $config);
		$config = str_replace('%FOLDERPERM%', $folder_permissions_octal, $config);
		$config = str_replace('%ADMIN_URL%', '/'.$url_params['admin_url'], $config);
		$config = str_replace('%DATABASEHOST%', $db_params['host'], $config);
		$config = str_replace('%DATABASEUSER%', $db_params['mysql_user'], $config);
		$config = str_replace('%DATABASEPASS%', $db_params['mysql_password'], $config);
		$config = str_replace('%DATABASENAME%', $db_params['db_name'], $config);
		
		$config_path = PATH_INSTALL.'/config';
		$config_file_path = $config_path.'/config.php';

		if (!file_exists($config_path))
			@mkdir($config_path);

		if (!is_writable($config_path))
			throw new Exception('Unable to create configuration file: '.$config_file_path.' - the config directory is not writable for PHP. Please try to use a less restrictive folder permission mask. You will need to empty the installation directory and restart the installer.');
		
		if (@file_put_contents($config_file_path, $config) === false)
			throw new Exception('Unable to create configuration file: '.$config_file_path);
	}

	public static function generate_index_file()
	{
		$installer = Phpr_Installer::create();
		extract($installer->get_file_permissions());

		$index = file_get_contents(PATH_INSTALL_APP.'/install_files/templates/index.tpl');
		$index_file_path = PATH_INSTALL.'/index.php';

		if (@file_put_contents($index_file_path, $index) === false)
			throw new Exception('Unable to create index file: '.$index_file_path);

		@chmod($index_file_path, $file_permissions);
	}

	public static function boot_framework()
	{
		// Validate framework exists first
		if (!file_exists(PATH_INSTALL.'/framework/boot.php'))
			throw new Exception('Unable to boot framework');

		global $APP_CONF;
		$APP_CONF = array();
		$PHPR_INIT_ONLY = true;
		include PATH_INSTALL.'/index.php';
	}

	// Create database objects
	public static function build_database()
	{
		self::boot_framework();

		$crypt = Phpr_Installer_Crypt::create();
		$config_content = array();

		$license_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params1.dat', self::$install_key);
		$config_content['birthmark'] = $birthmark = $license_params['birthmark'];
		$config_content['license_name'] = $license_name = $license_params['name'];
		$config_content['license_key'] = $license_key = $license_params['key'];
		$config_content['license_hash'] = $license_hash = $license_params['hash'];
		
		$encrypt_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params6.dat', self::$install_key);
		$config_content['config_key']  = $encrypt_params['enc_key'];

		$framework = Phpr_SecurityFramework::create();
		$framework->set_config_content($config_content);
		$framework->reset_instance();

		$framework = Phpr_SecurityFramework::create()->reset_instance();
		Db_Update_Manager::update();

		Phpr_Module_Parameters::set('core', 'birthmark', $birthmark);  
		Phpr_Module_Parameters::set('core', 'license_name', base64_encode($framework->encrypt($license_name)));
		Phpr_Module_Parameters::set('core', 'license_key', base64_encode($framework->encrypt($license_key)));
		Phpr_Module_Parameters::set('core', 'license_hash', base64_encode($framework->encrypt($license_hash)));
		Phpr_Module_Parameters::set('core', 'app_name', $license_params['app_name']);
		Phpr_Module_Parameters::set('core', 'app_image', $license_params['app_image']);
		Phpr_Module_Parameters::set('core', 'vendor_name', $license_params['vendor_name']);
		Phpr_Module_Parameters::set('core', 'vendor_url', $license_params['vendor_url']);
	}

	// Create administrator account
	public static function create_admin_account()
	{
		self::boot_framework();

		$crypt = Phpr_Installer_Crypt::create();
		$admin_user_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params5.dat', self::$install_key);

		// An admin user already exists
		if (Admin_User::create()->find())
			return;

		$user = new Admin_User();
		$user->first_name = $admin_user_params['firstname'];
		$user->last_name = $admin_user_params['lastname'];
		$user->email = $admin_user_params['email'];
		$user->login = $admin_user_params['login'];
		$user->password = $admin_user_params['password'];
		$user->password_confirm = $admin_user_params['password'];
		$user->save();
		
		Db_Helper::query("insert into admin_groups_users(admin_user_id, admin_group_id) values(:user_id, (select id from admin_groups where code='administrator'))", array('user_id'=>$user->id));
	}
	
	// Create the default theme
	public static function create_default_theme()
	{
		self::boot_framework();

		$theme = new Cms_Theme();
	
		$crypt = Phpr_Installer_Crypt::create();
		$app_info = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params1.dat', self::$install_key);
		$theme->name = $app_info['theme_name'];
		$theme->code = $app_info['theme_code'];
		$theme->description = "Default theme for ".$app_info['name'];
		$theme->author_website = $app_info['vendor_url'];
		$theme->author_name = $app_info['vendor_name'];
		$theme->is_default = true;
		$theme->enabled = true;
		$theme->save();

		Cms_Theme::auto_create_all_from_files();
	}

	// Finalise installation
	public static function install_finish()
	{
		// Check if existing .htaccess file defines the PHP5 handler
		// and capture it's original content
		$php5_handler = null;
		$original_htaccess_contents = null;
		if (file_exists(PATH_INSTALL.'/.htaccess'))
		{
			$original_htaccess_contents = $ht_contents = file_get_contents(PATH_INSTALL.'/.htaccess');
			$matches = array();
			if (preg_match('/AddHandler\s+(.*)\s+\.php/im', $ht_contents, $matches))
				$php5_handler = trim($matches[0]);
		}

		try
		{
			// Create .htaccess file
			if (!@copy(PATH_INSTALL_APP.'/install_files/templates/htaccess.tpl', PATH_INSTALL.'/.htaccess'))
				throw new Exception('Unable to create the .htaccess file: '.PATH_INSTALL.'/.htaccess');
				
			if ($php5_handler)
			{
				$ht_contents = file_get_contents(PATH_INSTALL.'/.htaccess');
				$ht_contents = $php5_handler."\n\n".$ht_contents;
				@file_put_contents(PATH_INSTALL.'/.htaccess', $ht_contents);
			}

			// Clean up
			self::install_cleanup();

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

	public static function install_cleanup()
	{
		$path = PATH_INSTALL_APP.'/temp';
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

	/**
	 * Helpers
	 */

	public static function request_server_data($url, $params = array())
	{
		$result = null;
		try
		{
			$post_data = http_build_query($params, '', '&');

			$ch = curl_init(); 
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION , true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
			$result = curl_exec($ch);
		} 
		catch (Exception $ex) {}

		if (!$result || !strlen($result))
			throw new Exception("Unable to connect to the ".APP_NAME." server.");

		return $result;
	}        

	public static function extract_file($src_file, $dest_path)
	{
		$installer = Phpr_Installer::create();
		extract($installer->get_file_permissions());

		global $installer_zip_file_permissions;
		global $installer_zip_folder_permissions;

		$installer_zip_file_permissions = $file_permissions;
		$installer_zip_folder_permissions = $folder_permissions;

		Zip_Helper::unzip($src_file, $dest_path, $file_permissions, $folder_permissions);
	}

	public static function find_file($path, $file_match) 
	{ 
		$iterator = new DirectoryIterator($path);
		foreach ($iterator as $file) 
		{ 
			if ($file->isDot())
				continue;
			else if ($file->isDir()) 
			{
				$result = self::find_file($file->getPathname(), $file_match); 
				if ($result !== null)
					return $result;
			}
			else if ($file_match == $file->getFilename())
				return $file->getPathname();
		}
		
		return null;
	}
	
	public static function copy_directory($source, $destination, &$options = array())
	{
		$ignore_files = isset($options['ignore']) ? $options['ignore'] : array();
		$overwrite_files = isset($options['overwrite']) ? $options['overwrite'] : true;

		if (is_dir($source))
		{
			if (!file_exists($destination))
				@mkdir($destination);

			$dir_obj = dir($source);

			while (($file = $dir_obj->read()) !== false) 
			{
				if ($file == '.' || $file == '..')
					continue;

				if (in_array($file, $ignore_files))
					continue;

				$dir_path = $source . '/' . $file;
				if (!is_dir($dir_path))
				{
					$dest_path = $destination . '/' . $file;
					if ($overwrite_files || !file_exists($dest_path))
						copy($dir_path, $dest_path);
				}
				else
				{
					self::copy_directory($dir_path, $destination . '/' . $file, $options);
				}
			}

			$dir_obj->close();
		} 
		else 
		{
			copy($source, $destination);
		}
	}    

	public static function delete_recursive($path) 
	{
		if (!is_dir($path)) 
			return false;

		$iterator = new DirectoryIterator($path);
		foreach ($iterator as $file) 
		{
			if ($file->isDot())
				continue;

			$dir_path = $file->getPathname();

			if (!is_link($dir_path) && $file->isDir()) 
				self::delete_recursive($dir_path);
			else
				@unlink($dir_path);
		}
		
		@rmdir($path);
		return true;
	}	
}