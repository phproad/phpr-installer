<?php

class Phpr_Installer_Manager
{

    private static $install_key = null;

    /**
     * Package Specific
     */ 
    
    public static function get_package_list()
    {
        $obj = Phpr_Installer::create();
        return "'" . implode("','", array_keys($obj->core_modules)) . "'";
    }

    public static function download_package($hash, $code, $downloaded_hash)
    {
        $tmp_file = self::get_package_file_path($name);
        $result = self::request_gateway_data('get_file/'.$hash.'/'.$code);
        
        if (trim($result) == "")
            throw new Exception("Package is empty: ".$name);

        $tmp_save_result = false;
        try
        {
            $tmp_save_result = @file_put_contents($tmp_file, $result['data']);
        }
        catch (Exception $ex)
        {
            throw new Exception("Unable create temporary file in ".$tmp_path);
        }

        if (!$tmp_save_result)
            throw new Exception("Unable create temporary file in ".$tmp_path);

        $downloaded_hash = md5_file($tmp_file);
        if ($downloaded_hash != $file_hash) {
            
            if (file_exists($tmp_file))
                @unlink($tmp_file);

            throw new Exception("Downloaded archive is corrupt. Please try the install again.");
        }

        return $tmp_file;
    }

    public static function unzip_package($name)
    {
        $name = strtolower($name);
        $tmp_file = self::get_package_file_path($name);
        $tmp_path = dirname($tmp_file).DS.$name.DS;

        if (!file_exists($tmp_path))
            @mkdir($tmp_path);

        if (!is_writable($tmp_path))
            throw new Exception("Unable to write to path ".$tmp_path);

        self::extract_file($tmp_file, $tmp_path);

        $tmp_module_file_path = self::find_file($tmp_path, $name.'_module.php');
        if (!$tmp_module_file_path)
            throw new Exception("Package ".$name." does not appear to be a valid module.");


        $tmp_module_path = dirname(dirname($tmp_module_file_path));
        $module_path = PATH_INSTALL.DS.'modules'.DS.$name;
        
        if (!file_exists($module_path))
            @mkdir($module_path, 0, true);

        self::copy_directory($tmp_module_path, $module_path);
        self::delete_recursive($tmp_path);
        @unlink($tmp_file);
    }

    public static function get_package_file_path($name)
    {
        $tmp_path = PATH_INSTALL_APP.DS.'temp';

        if (!file_exists($tmp_path))
            @mkdir($tmp_path);

        if (!is_writable($tmp_path))
            throw new Exception("Unable to write to path ".$tmp_path);
        
        $tmp_file = $tmp_path.DS.strtolower($name).'.arc';

        return $tmp_file;
    }
    
    /**
     * Helpers
     */

    public static function request_server_data($url, $fields = array())
    {
        $result = null;
        try
        {
            $poststring = array();

            foreach($fields as $key=>$val)
                $poststring[] = urlencode($key)."=".urlencode($val); 

            $poststring = implode('&', $poststring);

            $ch = curl_init(); 
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION , true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $poststring);
            $result = curl_exec($ch);
        } 
        catch (Exception $ex) {}

        if (!$result || !strlen($result))
            throw new Exception("Unable to connect to the ".APP_NAME." server.");

        return $result;
    }        

    public static function extract_file($src_file, $dest)
    {
        if (!class_exists('ZipArchive'))
            throw new Exception("Your PHP installation does not have the Zip library. You can install it by recompiling PHP with the --enable-zip option or check the install documentation for alternate instructions.");
          
        $zip = new ZipArchive;
        $response = $zip->open($src_file);
        if ($response === TRUE) 
        {
            $zip->extractTo($dest);
            $zip->close();
        }
        else
        {
            throw new Exception("Cannot uncompress package, Code: ".$response);
        }
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

    public static function make_dir($path, $permissions)
    {
        if (!file_exists($path))
            @mkdir($path);
        
        @chmod($path, $permissions);
    }

    public static function deny_dir_access($path, $permissions)
    {
        $path .= '/.htaccess';
        
        $data = "order deny,allow\ndeny from all";
        @file_put_contents($path, $data);
        
        @chmod($path, $permissions);
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

    /**
     * Gateway Specific 
     */

    public static function request_gateway_data($url, $fields = array())
    {
        $result = self::request_server_data(URL_GATEWAY.'/'.$url, $fields);

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

    public function validate_website_config($holder_name, $installation_key, $generate_key)
    {
        if (!strlen($holder_name))
            throw new ValidationException('Please enter licence holder name.', 'holder_name');

        if (!strlen($installation_key) && (defined('DISABLE_KEYLESS_ENTRY') || !$generate_key))
            throw new ValidationException('Please enter installation key.', 'installation_key');

        if ($generate_key)
            $hash = 'keyless';
        else
            $hash = md5($installation_key.$holder_name);

        $data = array(
            'url' => base64_encode($this->get_root_url())
        );

        $result = self::request_gateway_data('get-install-hashes/'.$hash, $data);
        if (!is_array($result['data']))
            throw new Exception("Invalid server response");

        $birthmark = $result['data']['birthmark'];
        $file_hashes = $result['data']['file_hashes'];
        $licence_key = $result['data']['key'];
        $application_name = $result['data']['application_name'];
        $application_image = $result['data']['application_image'];
        $theme_code = $result['data']['theme_code'];
        $theme_name = $result['data']['theme_name'];
        $vendor_name = $result['data']['vendor_name'];
        $vendor_url = $result['data']['vendor_url'];

        /*

        $tmp_path = PATH_INSTALL_APP.'/temp';
        if (!is_writable($tmp_path))
            throw new Exception("Cannot create temporary file. Path is not writable: ".$tmp_path);

        $files = array();
        try
        {
            
            foreach ($file_hashes as $code=>$file_hash)
            {
                $tmp_file = $tmp_path.'/'.$code.'.arc';
                $result = self::request_gateway_data('get_file/'.$hash.'/'.$code);

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
        */

        $install_params = array(
            'hash'        => $hash,
            'birthmark'   => $birthmark,
            'key'         => $licence_key,
            'holder'      => $holder_name,
            'app_name'    => $application_name,
            'app_image'   => $application_image,
            'theme_name'  => $theme_name,
            'theme_code'  => $theme_code,
            'vendor_name' => $vendor_name,
            'vendor_url'  => $vendor_url,
            'file_hashes' => $file_hashes
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

    public static function install_phpr($install_key)
    {
        self::$install_key = $install_key;

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
            // Generate files
            self::generate_file_strut();
            self::generate_config_file();
            
            // @disabled
            //self::generate_template_file('index', PATH_INSTALL.'/index.php');
            //self::generate_htaccess_file($php5_handler);
            self::generate_template_file('application', PATH_INSTALL.'/controllers/application.php');
            self::generate_template_file('init', PATH_INSTALL.'/init/init.php');
            self::generate_template_file('routes', PATH_INSTALL.'/init/routes.php');

            // Validate framework exists
            if (!file_exists(PATH_INSTALL.'/index.php'))
                throw new Exception('Fatal Error: Unable to locate framework boot file after install');

            // Build base elements
            self::build_database();
            self::create_admin_account();
            self::create_default_theme();

            // Finalize installation
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

    // Unzips all ARC packages found in the temp folder
    public static function generate_file_strut()
    {
        $crypt = Install_Crypt::create();
        $system_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params4.dat', self::$install_key);
        extract(self::get_file_permissions($system_params));

        $path = PATH_INSTALL_APP.'/temp';
        $iterator = new DirectoryIterator($path);
        foreach ($iterator as $file) 
        {
            if ($file->isDir() || $file->isDot())
                continue;
            
            $file_extension = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
            if ($file_extension != 'arc')
                continue;

            $zip_file_permissions = $file_permissions;
            $zip_folder_permissions = $folder_permissions;
            Zip_Helper::unzip(PATH_INSTALL, $file->getPathname(), $zip_file_permissions, $zip_folder_permissions);
        }

        // Set up folders
        self::make_dir(PATH_INSTALL.'/controllers', $folder_permissions);
        self::make_dir(PATH_INSTALL.'/init', $folder_permissions);
        self::make_dir(PATH_INSTALL.'/uploaded/public', $folder_permissions);
        self::make_dir(PATH_INSTALL.'/uploaded/thumbnails', $folder_permissions);        
        self::make_dir(PATH_INSTALL.'/logs', $folder_permissions);
        self::deny_dir_access(PATH_INSTALL.'/logs', $folder_permissions);
        self::deny_dir_access(PATH_INSTALL.'/temp', $folder_permissions);
    }

    // Create .htaccess file
    public static function generate_htaccess_file($php5_handler)
    {
        if (!@copy(PATH_INSTALL_APP.'/templates/htaccess.tpl', PATH_INSTALL.'/.htaccess'))
            throw new Exception('Unable to create the .htaccess file: '.PATH_INSTALL.'/.htaccess');
            
        if ($php5_handler)
        {
            $ht_contents = file_get_contents(PATH_INSTALL.'/.htaccess');
            $ht_contents = $php5_handler."\n\n".$ht_contents;
            @file_put_contents(PATH_INSTALL.'/.htaccess', $ht_contents);
        }
    }

    // Generate config file
    public static function generate_config_file()
    {
        $crypt = Install_Crypt::create();
        $db_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params2.dat', self::$install_key);
        $url_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params3.dat', self::$install_key);
        $system_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params4.dat', self::$install_key);
        extract(self::get_file_permissions($system_params));

        $config = file_get_contents(PATH_INSTALL_APP.'/templates/config.tpl');          

        $config = str_replace('%APP_NAME%', APP_NAME, $config);
        $config = str_replace('%TIMEZONE%', $system_params['time_zone'], $config);
        $config = str_replace('%FILEPERM%', $file_permissions_octal, $config);
        $config = str_replace('%FOLDERPERM%', $folder_permissions_octal, $config);
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

    // Generates a template file safely (no overwrite)
    public static function generate_template_file($name, $dest_file)
    {
        if (!file_exists($dest_file))
        {
            $template_content = file_get_contents(PATH_INSTALL_APP.'/templates/'.$name.'.tpl');
            if (@file_put_contents($dest_file, $template_content) === false)
                throw new Exception('Unable to create '.$name.'.php file.');
        }
    }

    public static function get_file_permissions($system_params)
    {
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

    // Create database objects
    public static function build_database()
    {
        $APP_CONF = array();
        $PHPR_INIT_ONLY = true;
        include PATH_INSTALL.'/index.php';

        $crypt = Install_Crypt::create();
        $config_content = array();

        if (defined('URL_GATEWAY'))
        {
            $licence_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params1.dat', self::$install_key);
            $config_content['hash'] = $licence_params['hash'];
            $config_content['licence_key'] = $licence_params['key'];
            $config_content['licence_holder'] = $licence_params['holder'];
        }
        
        $encrypt_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params6.dat', self::$install_key);
        $config_content['config_key']  = $encrypt_params['enc_key'];

        $framework = Phpr_SecurityFramework::create();
        $framework->set_config_content($config_content);
        $framework->reset_instance();

        $framework = Phpr_SecurityFramework::create()->reset_instance();
        Db_Update_Manager::update();

        if (defined('URL_GATEWAY'))
        {
            Db_Module_Parameters::set('core', 'hash', base64_encode($framework->encrypt($licence_hash)));
            Db_Module_Parameters::set('core', 'licence_key', $licence_key);  
        }
    }

    // Create administrator account
    public static function create_admin_account()
    {
        $crypt = Install_Crypt::create();
        $admin_user_params = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params5.dat', self::$install_key);

        $user = new Admin_User();
        $user->first_name = $admin_user_params['firstname'];
        $user->last_name = $admin_user_params['lastname'];
        $user->email = $admin_user_params['email'];
        $user->login = $admin_user_params['login'];
        $user->password = $admin_user_params['password'];
        $user->password_confirm = $admin_user_params['password'];
        $user->save();
        
        Db_DbHelper::query("insert into admin_groups_users(admin_user_id, admin_group_id) values(LAST_INSERT_ID(), (select id from admin_groups where code='administrator'))");
    }
    
    // Create the default theme
    public static function create_default_theme()
    {
        $theme = new Cms_Theme();
    
        if (defined('URL_GATEWAY'))
        {        
            $crypt = Install_Crypt::create();
            $app_info = $crypt->decrypt_from_file(PATH_INSTALL_APP.'/temp/params1.dat', self::$install_key);
            $theme->name = $app_info['theme_name'];
            $theme->code = $app_info['theme_code'];
            $theme->description = "Default theme for ".$app_info['name'];
            $theme->author_website = $app_info['vendor_url'];
            $theme->author_name = $app_info['vendor_name'];
        }
        else
        {
            $theme->name = "Default";
            $theme->code = "default";
        }
        
        $theme->default_theme = true;
        $theme->enabled = true;
        $theme->save();

        Cms_Theme::auto_create_all_from_files();
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
}