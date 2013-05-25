<?php

$installer_zip_file_permissions = 0777;
$installer_zip_folder_permissions = 0777;

class Zip_Helper
{
	protected static $_initialized = false;
	
	public static function unzip($archive_path, $destination_path, $file_permissions = null, $folder_permissions = null)
	{
		global $installer_zip_file_permissions;
		global $installer_zip_folder_permissions;
		
		if ($file_permissions)
			$installer_zip_file_permissions = $file_permissions;

		if ($folder_permissions)
			$installer_zip_folder_permissions = $folder_permissions;
		
		if (!file_exists($archive_path))
			throw new Exception('Archive file is not found: '.$archive_path);

		if (!is_writable($destination_path))
			throw new Exception('Error unpacking ZIP archive. Directory is not writable: '.$destination_path);

		self::init_zip();
		$archive = new PclZip($archive_path);
		
		if (@$archive->extract(PCLZIP_OPT_PATH, $destination_path, PCLZIP_CB_POST_EXTRACT, 'installer_zip_post_extract_callback') === 0)
			throw new Exception('Error unpacking archive: '.$archive->errorInfo(true));
	}

	public static function init_zip()
	{
		if (self::$_initialized)
			return;
		
		require_once(PATH_INSTALL_APP."/install_files/classes/pclzip.lib.php");

		if (!defined('PCLZIP_TEMPORARY_DIR'))
		{
			if (!is_writable(PATH_INSTALL))
				throw new Exception('Error initializing ZIP helper. Directory is not writable: '.PATH_INSTALL);
			
			define('PCLZIP_TEMPORARY_DIR', PATH_INSTALL);
		}
			
		self::$_initialized = true;
	}
}

function installer_zip_post_extract_callback($p_event, &$p_header)
{
	global $installer_zip_file_permissions;
	global $installer_zip_folder_permissions;

	if ($installer_zip_file_permissions !== null && file_exists($p_header['filename']))
	{
		$is_folder = array_key_exists('folder', $p_header) ? $p_header['folder'] : false;
		$mode = $is_folder ? $installer_zip_folder_permissions : $installer_zip_file_permissions;
		@chmod($p_header['filename'], $mode);
	}
	return 1;
}
