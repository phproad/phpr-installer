<?php

/**
 * PHPR Installer 
 * 
 * This is the base file for installing, if you want to
 * customise this you should look at the Bootstrap Installer
 * package instead.
 * 
 */

define('APP_NAME', 'PHPRoad'); 
define('URL_TERMS', 'http://phproad.com/resources/phpr-license.txt');
define('URL_DOCS', 'http://phproad.com/docs');
define('URL_GATEWAY', 'http://api.phproad.com/update_gateway');

$is_php4 = version_compare(PHP_VERSION, '5.0.0', '<');
define('DS', DIRECTORY_SEPARATOR);
define('PHP4_DETECTED', $is_php4);
define('PATH_INSTALL', str_replace("\\", "/", realpath(dirname(__FILE__)."/") ) );
define('PATH_INSTALL_APP', str_replace("\\", "/", realpath(dirname(__FILE__)."/") ) );

if (!PHP4_DETECTED) 
{
	require "install_files/classes/phpr_installer.php";
	$installer = Phpr_Installer::create();

	if ($installer->check_remote_event())
		die($installer->output_install_page());

	if ($installer->detect_cli()) {
		echo "-------------------------------------------------------------------------".PHP_EOL;
		echo "Sorry this application cannot be installed via command line at this time.".PHP_EOL; 
		echo "-------------------------------------------------------------------------".PHP_EOL;
		die();
	}
}

?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width">
		<title><?=APP_NAME?> Installer</title>
		<?=$installer->display_head()?>
	</head>
	<body>

		<div class="content">
			<div class="content-inner">

				<? if (PHP4_DETECTED): ?>
					<h1>PHP 4 Detected</h1>
					<h3>We are sorry but <?php echo APP_NAME; ?> requires PHP 5.</h3>
					<p>We detected that your server is using PHP 4. To complete the installation you will need to upgrade this server to run PHP 5 with the required libraries or use another server.</p>
					<p>To install <?php echo APP_NAME; ?> the server must meet the following requirements:</p>

					<blockquote>
						<ul class="bullets">
							<li class="tick">PHP 5.3 or higher</li>
							<li class="tick">PHP CURL library</li>
							<li class="tick">PHP OpenSSL library</li>
							<li class="tick">PHP Mcrypt library</li>
							<li class="tick">PHP MySQL functions</li>
							<li class="tick">PHP Multibyte String functions</li>
							<li class="tick">Permissions for PHP to write to the installation directory</li>
						</ul>
					</blockquote>

				<? else: ?>
					<?php echo $installer->output_install_page() ?>
				<? endif ?>

			</div>
		</div>

	</body>
</html>
