SetEnv DEFAULT_PHP_VERSION 5

RewriteEngine on
# RewriteOptions MaxRedirects=1
# RewriteBase /

# Only execute index.php
RewriteRule ^(.*).php$ index.php [L]

# Add trailing slash
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_URI} !(\.[a-zA-Z0-9]{1,5}|/)$
RewriteCond %{HTTP:X-REQUESTED-WITH} !^(XMLHttpRequest)$
RewriteCond %{REQUEST_METHOD} !^HEAD$
RewriteCond %{REQUEST_METHOD} !^POST$
RewriteRule (.*)([^/])$ %{REQUEST_URI}/ [R=301,L]

# Allow sitemap.xml through the gates
RewriteRule sitemap.xml index.php?q=/sitemap.xml [L]

# Allow normal files and third party libs
RewriteCond %{REQUEST_URI} !(\.(ico|js|jpg|gif|css|less|png|swf|txt|xml|xls|eot|woff|ttf|svg)$)
RewriteCond %{REQUEST_URI} !(framework/vendor/.*)
RewriteRule ^(.*)$ index.php?q=/$1 [L,QSA]

ErrorDocument 404 "File not found"

#
# PHP configuration
#

<IfModule mod_php5.c>
php_flag session.auto_start off
php_value session.cookie_lifetime 31536000
php_flag session.use_cookies on
php_flag session.use_only_cookies on
php_value session.name AHOYSESSID

php_flag display_errors on
php_flag short_open_tag on
php_flag asp_tags on

php_flag magic_quotes_gpc off
php_value date.timezone GMT

php_value post_max_size 100M
php_value upload_max_filesize 100M

php_value memory_limit 264M
</IfModule>