.htaccess
in htdocs

RewriteEngine On
RewriteBase /
# Homepage
RewriteRule ^$ cusg/views/index.html [L]
# Any *.html request -> serve from cusg/views/
RewriteCond %{REQUEST_URI} !^/cusg/
RewriteRule ^([^/]+\.html)$ cusg/views/$1 [L]
# Everything else (assets, api, controllers, etc.) -> cusg/
RewriteCond %{REQUEST_URI} !^/cusg/
RewriteRule ^(.*)$ cusg/$1 [L]